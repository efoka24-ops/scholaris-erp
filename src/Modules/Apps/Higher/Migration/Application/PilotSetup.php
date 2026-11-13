<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Migration\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Higher\Academic\Infrastructure\Store;
use Scholaris\Modules\Apps\Higher\Billing\Infrastructure\BillingRepository;
use Scholaris\Modules\Apps\Higher\Documents\Infrastructure\DocumentsRepository;
use Scholaris\Modules\Apps\Higher\Migration\Domain\PilotTemplates;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentDirectory;
use Scholaris\Modules\Socle\Organisation\Contracts\StructureDraft;
use Scholaris\Modules\Socle\Organisation\Contracts\StructureWriter;

/**
 * Squelette de démarrage d'un IPES pilote déjà créé : écoles internes, départements, filières-gabarits FERMÉES « [à confirmer] »,
 * niveaux, groupe G1, profil documentaire de chaque école et grille de frais VIDE (brouillon). Aucune autorisation MINESUP : les
 * filières restent non inscriptibles. Idempotent (ce qui existe est conservé) ; `dryRun` ne crée rien.
 */
final class PilotSetup
{
    public const ACTOR = 'system:pilot-setup';

    public function __construct(
        private readonly Store $store,
        private readonly StructureWriter $writer,
        private readonly EstablishmentDirectory $establishments,
        private readonly DocumentsRepository $documents,
        private readonly BillingRepository $billing,
        private readonly AuditLog $audit,
        private readonly MigrationClock $clock,
    ) {
    }

    /**
     * @return SetupReport
     */
    public function run(string $tenantId, string $template, ?string $establishmentId, ?string $yearId, bool $dryRun): SetupReport
    {
        $model = PilotTemplates::get($template) ?? throw HttpException::validation(['template' => ['Modèle inconnu : ' . implode(', ', PilotTemplates::names()) . '.']]);
        $establishment = $this->establishment($tenantId, $establishmentId);
        $result = new SetupReport();
        $run = function () use ($tenantId, $establishment, $model, $yearId, $dryRun, $result): void {
            foreach ($model['schools'] as $school) {
                $schoolId = $this->ensure($result, $dryRun, 'école', $school['code'], 'hi_schools', $tenantId, $establishment, ['code' => $school['code']], function () use ($tenantId, $establishment, $school): array {
                    $id = $this->writer->createOrgUnit($tenantId, $establishment, 'school', $school['name'], null, null);

                    return ['id' => $id, 'code' => $school['code'], 'name' => $school['name'], 'director_person_id' => null];
                });
                $departmentId = $this->ensure($result, $dryRun, 'département', $school['department']['code'], 'hi_departments', $tenantId, $establishment, ['school_id' => $schoolId ?? '-', 'code' => $school['department']['code']], function () use ($tenantId, $establishment, $school, $schoolId): array {
                    $id = $this->writer->createOrgUnit($tenantId, $establishment, 'department', $school['department']['name'], (string) $schoolId, null);

                    return ['id' => $id, 'school_id' => $schoolId, 'code' => $school['department']['code'], 'name' => $school['department']['name'], 'head_person_id' => null];
                });
                foreach ($school['programs'] as $program) {
                    $name = mb_substr($program['name'], 0, 160 - mb_strlen(PilotTemplates::SUFFIX)) . PilotTemplates::SUFFIX;
                    $programId = $this->ensure($result, $dryRun, 'filière', $program['code'], 'hi_programs', $tenantId, $establishment, ['school_id' => $schoolId ?? '-', 'code' => $program['code']], function () use ($tenantId, $establishment, $program, $name, $schoolId, $departmentId): array {
                        $id = $this->writer->createStructure($tenantId, $establishment, new StructureDraft(StructureDraft::TRACK, $name, (string) $departmentId), null);

                        return [
                            'id' => $id, 'school_id' => $schoolId, 'department_id' => $departmentId, 'parent_program_id' => null, 'code' => $program['code'], 'name' => $name,
                            'regime' => $program['regime'], 'teaching_language' => 'fr', 'study_mode' => 'day', 'duration_years' => $program['years'], 'status' => 'closed',
                        ];
                    });
                    for ($ordinal = 1; $ordinal <= $program['years']; ++$ordinal) {
                        $levelCode = $program['regime'] === 'bts' ? 'BTS' . $ordinal : 'N' . $ordinal;
                        $levelName = $program['regime'] === 'bts' ? 'BTS ' . $ordinal . ' (année)' : 'Niveau ' . $ordinal;
                        $levelId = $this->ensure($result, $dryRun, 'niveau', $program['code'] . '/' . $levelCode, 'hi_levels', $tenantId, $establishment, ['program_id' => $programId ?? '-', 'ordinal' => $ordinal], function () use ($tenantId, $establishment, $schoolId, $departmentId, $programId, $levelName, $levelCode, $ordinal): array {
                            $id = $this->writer->createStructure($tenantId, $establishment, new StructureDraft(StructureDraft::LEVEL, $levelName, (string) $departmentId, (string) $programId), null);

                            return ['id' => $id, 'school_id' => $schoolId, 'department_id' => $departmentId, 'program_id' => $programId, 'ordinal' => $ordinal, 'code' => $levelCode, 'name' => $levelName];
                        });
                        $this->ensure($result, $dryRun, 'groupe', $program['code'] . '/' . $levelCode . '/G1', 'hi_groups', $tenantId, $establishment, ['level_id' => $levelId ?? '-', 'code' => 'G1'], function () use ($tenantId, $establishment, $schoolId, $departmentId, $programId, $levelId, $levelName): array {
                            $id = $this->writer->createStructure($tenantId, $establishment, new StructureDraft(StructureDraft::GROUP, 'Groupe 1 · ' . $levelName, (string) $departmentId, (string) $levelId, null), null);

                            return ['id' => $id, 'school_id' => $schoolId, 'department_id' => $departmentId, 'program_id' => $programId, 'level_id' => $levelId, 'code' => 'G1', 'name' => 'Groupe 1 · ' . $levelName, 'capacity' => null];
                        });
                    }
                }
                $this->profile($result, $dryRun, $tenantId, $establishment, $school['code'], $school['name'], $schoolId);
                if ($yearId !== null) {
                    $this->grid($result, $dryRun, $tenantId, $establishment, $yearId, $school['code'], $schoolId);
                }
            }
        };
        if ($dryRun) {
            $run();
        } else {
            $this->store->transaction(function () use ($run, $tenantId, $establishment, $template, $result): void {
                $run();
                $this->audit->append($tenantId, null, 'higher.pilot_setup', 'establishment', $establishment, null, ['template' => $template, 'created' => $result->created]);
            });
        }
        $result->lines[] = 'Rappel : aucune autorisation MINESUP n\'a été créée. Les filières sont fermées « [à confirmer] » et ne sont pas inscriptibles tant qu\'une autorisation réelle n\'est pas saisie (Paramétrage académique > Autorisations) et la filière rouverte.';
        if ($yearId === null) {
            $result->lines[] = 'Aucune année indiquée (--year) : la grille de frais vide n\'a pas été créée.';
        }

        $result->establishmentId = $establishment;
        $result->template = $template;
        $result->dryRun = $dryRun;

        return $result;
    }

    private function establishment(string $tenantId, ?string $wanted): string
    {
        $higher = array_values(array_filter($this->establishments->forTenant($tenantId), static fn ($e): bool => $e->type === 'higher'));
        if ($higher === []) {
            throw HttpException::validation(['client' => ['Aucun établissement de type « higher » pour ce client : créez-le d\'abord (establishment:create).']]);
        }
        if ($wanted !== null) {
            foreach ($higher as $candidate) {
                if ($candidate->id === $wanted) {
                    return $candidate->id;
                }
            }
            throw HttpException::validation(['establishment' => ['Établissement introuvable parmi les IPES de ce client.']]);
        }
        if (count($higher) > 1) {
            throw HttpException::validation(['establishment' => ['Ce client a plusieurs IPES : précisez --establishment=<id>.']]);
        }

        return $higher[0]->id;
    }

    /**
     * @param array<string, scalar|null> $where
     * @param callable(): array<string, scalar|null> $create
     */
    private function ensure(SetupReport $result, bool $dryRun, string $kind, string $label, string $table, string $tenantId, string $establishmentId, array $where, callable $create): ?string
    {
        $existing = in_array('-', $where, true) ? null : $this->store->select($table, $tenantId, $establishmentId, $where, 'id', 1)[0] ?? null;
        if ($existing !== null) {
            $result->keep($kind);
            $result->lines[] = sprintf('%s %s : existe déjà, conservé.', ucfirst($kind), $label);

            return (string) $existing['id'];
        }
        $result->create($kind);
        $result->lines[] = sprintf('%s %s : %s.', ucfirst($kind), $label, $dryRun ? 'serait créé' : 'créé');
        if ($dryRun) {
            return null;
        }
        $row = $create();
        $now = $this->clock->now();
        $this->store->insert($table, $tenantId, $establishmentId, $row + ['created_by' => self::ACTOR, 'created_at' => $now, 'updated_at' => $now]);

        return (string) $row['id'];
    }

    private function profile(SetupReport $result, bool $dryRun, string $tenantId, string $establishmentId, string $code, string $name, ?string $schoolId): void
    {
        if ($schoolId !== null && $this->documents->latestProfile($tenantId, $establishmentId, $schoolId) !== null) {
            $result->lines[] = 'Profil documentaire ' . $code . ' : existe déjà, conservé.';

            return;
        }
        $result->create('profil documentaire');
        $result->lines[] = 'Profil documentaire ' . $code . ' : ' . ($dryRun ? 'serait créé' : 'créé') . ' (en-tête, logo et couleurs à compléter).';
        if ($dryRun || $schoolId === null) {
            return;
        }
        $this->documents->insertProfile([
            'id' => (string) new Ulid(), 'tenant_id' => $tenantId, 'establishment_id' => $establishmentId, 'school_id' => $schoolId, 'version' => 1,
            'school_code' => mb_substr($code, 0, 10), 'display_name' => $name, 'header_text' => null, 'logo_url' => null, 'primary_color' => '#1F3A5F',
            'default_locale' => 'fr', 'created_by' => self::ACTOR, 'created_at' => $this->clock->now(),
        ]);
    }

    private function grid(SetupReport $result, bool $dryRun, string $tenantId, string $establishmentId, string $yearId, string $code, ?string $schoolId): void
    {
        $label = 'Grille de frais à compléter (gabarit) · ' . $code;
        if ($schoolId !== null && $this->billing->list(BillingRepository::GRIDS, $tenantId, $establishmentId, ['academic_year_id' => $yearId, 'school_id' => $schoolId, 'label' => $label], limit: 1) !== []) {
            $result->lines[] = 'Grille de frais ' . $code . ' : existe déjà, conservée.';

            return;
        }
        $result->create('grille de frais');
        $result->lines[] = 'Grille de frais ' . $code . ' : ' . ($dryRun ? 'serait créée' : 'créée') . ' vide, en brouillon (montants et échéancier à saisir, puis à activer).';
        if ($dryRun || $schoolId === null) {
            return;
        }
        $now = $this->clock->now();
        $this->billing->insert(BillingRepository::GRIDS, [
            'id' => (string) new Ulid(), 'tenant_id' => $tenantId, 'establishment_id' => $establishmentId, 'academic_year_id' => $yearId, 'school_id' => $schoolId,
            'program_id' => null, 'level_id' => null, 'study_mode' => null, 'label' => $label, 'fees_json' => '[]', 'terms_json' => '[]', 'status' => 'draft',
            'created_by' => self::ACTOR, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }
}
