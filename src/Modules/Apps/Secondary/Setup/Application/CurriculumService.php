<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Setup\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\EstablishmentProfiles;
use Scholaris\Modules\Apps\Secondary\Setup\Domain\SubjectRoles;
use Scholaris\Modules\Apps\Secondary\Support\Access;
use Scholaris\Modules\Apps\Secondary\Support\Input;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryClock;
use Scholaris\Modules\Apps\Secondary\Support\Store;
use Scholaris\Modules\Socle\Assessment\Contracts\CurriculumCatalog;
use Scholaris\Modules\Socle\Assessment\Contracts\CurriculumItem;

/**
 * Matières, coefficients et volumes horaires (T821, EX-SEC-09, RG-ORG-4), PARAMÉTRÉS PAR L'ÉTABLISSEMENT (décision du propriétaire) :
 *  - matière : projetée dans le référentiel d'évaluation du socle (CurriculumCatalog, clé `sec:<code>`), sans coefficient officiel ;
 *  - coefficient et volume : par année, niveau et, au besoin, série ou spécialité ; chaque modification crée une NOUVELLE version
 *    (numéro, date d'effet, auteur, motif) et clôt la précédente (`superseded_at`) : rien n'est supprimé, l'historique est complet ;
 *  - une ligne verrouillée (fixée par la plateforme, `locked = 1`) ne se modifie pas dans l'établissement (422 `coefficient_locked`) ;
 *  - une matière de projet (`project`) n'entre pas dans la moyenne officielle ;
 *  - une année gelée n'est plus modifiable (409) et n'est jamais recalculée ; une nouvelle année se prépare par copie.
 * Aucune valeur n'est livrée : tant que l'établissement n'a pas saisi, la liste est vide ; l'origine `example` marque une valeur
 * d'exemple à confirmer (Q2).
 */
final class CurriculumService
{
    public const KEY_PREFIX = 'sec:';
    public const LOCKED = 'https://api.scholaris.cm/errors/coefficient-locked';

    public function __construct(
        private readonly Store $store,
        private readonly Access $access,
        private readonly EstablishmentProfiles $profiles,
        private readonly CurriculumCatalog $catalog,
        private readonly SecondaryClock $clock,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function subjects(Principal $principal, string $establishmentId): array
    {
        $this->access->require($principal, $establishmentId, StructureService::READ);

        return array_map(self::subject(...), $this->store->select('se_subjects', $principal->tenantId, $establishmentId, [], 'code', 500));
    }

    /**
     * @param array<string, mixed> $body code, label, label_en?
     * @return array<string, mixed>
     */
    public function createSubject(Principal $principal, string $establishmentId, array $body): array
    {
        $this->writable($principal, $establishmentId);
        $in = new Input($body);
        $code = (string) $in->code('code', 20);
        $label = (string) $in->string('label', 160);
        $labelEn = $in->string('label_en', 160, false, true);
        $in->done();
        $tenantId = $principal->tenantId;
        if ($this->store->count('se_subjects', $tenantId, $establishmentId, ['code' => $code]) > 0) {
            throw HttpException::conflict('Une matière porte déjà ce code.');
        }
        $subjectId = $this->catalog->upsertSubject($tenantId, $establishmentId, new CurriculumItem(self::KEY_PREFIX . $code, $code, $label, 'subject'));
        $now = $this->clock->now();
        $id = (string) new Ulid();
        $this->store->insert('se_subjects', $tenantId, $establishmentId, [
            'id' => $id, 'code' => $code, 'label' => $label, 'label_en' => $labelEn, 'subject_id' => $subjectId, 'active' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->access->audit($principal, 'secondary.subject.created', 'se_subject', $id, null, ['code' => $code]);

        return self::subject($this->store->findOrFail('se_subjects', $tenantId, $establishmentId, $id));
    }

    /**
     * @param array<string, mixed> $body label?, label_en?, active?
     * @return array<string, mixed>
     */
    public function updateSubject(Principal $principal, string $establishmentId, string $id, array $body): array
    {
        $this->writable($principal, $establishmentId);
        $tenantId = $principal->tenantId;
        $row = $this->store->findOrFail('se_subjects', $tenantId, $establishmentId, $id, 'Matière introuvable.');
        $in = new Input($body, true);
        $label = $in->string('label', 160, false);
        $labelEn = $in->string('label_en', 160, false, true);
        $active = $in->bool('active', false);
        $values = $in->done();
        $changes = ['updated_at' => $this->clock->now()];
        if ($label !== null) {
            $changes['label'] = $label;
        }
        if (array_key_exists('label_en', $values)) {
            $changes['label_en'] = $labelEn;
        }
        if ($active !== null) {
            $changes['active'] = $active ? 1 : 0;
        }
        $key = self::KEY_PREFIX . $row['code'];
        if ($active === false) {
            $this->catalog->deactivate($tenantId, $establishmentId, $key);
        } elseif ($label !== null || $active === true) {
            $this->catalog->upsertSubject($tenantId, $establishmentId, new CurriculumItem($key, (string) $row['code'], $label ?? (string) $row['label'], 'subject'));
        }
        $this->store->update('se_subjects', $tenantId, $establishmentId, $changes, ['id' => $id]);
        $this->access->audit($principal, 'secondary.subject.updated', 'se_subject', $id, $row, $changes);

        return self::subject($this->store->findOrFail('se_subjects', $tenantId, $establishmentId, $id));
    }

    /**
     * Coefficients en vigueur (ou tout l'historique avec `history`) d'une année, filtrés par niveau ou série.
     *
     * @return array{lines: list<array<string, mixed>>, frozen: ?array<string, mixed>}
     */
    public function coefficients(Principal $principal, string $establishmentId, string $academicYearId, ?string $levelCode, ?string $streamId, bool $history): array
    {
        $this->access->require($principal, $establishmentId, StructureService::READ);
        $where = ['academic_year_id' => $academicYearId];
        if ($levelCode !== null) {
            $where['level_code'] = $levelCode;
        }
        if ($streamId !== null) {
            $where['stream_id'] = $streamId;
        }
        if (!$history) {
            $where['superseded_at'] = null;
        }
        $rows = $this->store->select('se_subject_coefficients', $principal->tenantId, $establishmentId, $where, 'level_code, stream_id, se_subject_id, version_no', 5000);
        $labels = $this->subjectLabels($principal->tenantId, $establishmentId);
        $lines = [];
        foreach ($rows as $row) {
            $lines[] = self::coefficient($row, $labels);
        }

        return ['lines' => $lines, 'frozen' => $this->freeze($principal->tenantId, $establishmentId, $academicYearId)];
    }

    /**
     * Pose ou modifie le coefficient et le volume d'une matière (nouvelle version, motif obligatoire).
     *
     * @param array<string, mixed> $body academic_year_id, level_code, stream_id?, subject_ref, coefficient, weekly_hours, subject_role, origin?, source_ref?, valid_from, reason
     * @return array<string, mixed>
     */
    public function setCoefficient(Principal $principal, string $establishmentId, array $body): array
    {
        $this->writable($principal, $establishmentId);
        $tenantId = $principal->tenantId;
        $in = new Input($body);
        $year = (string) $in->reference('academic_year_id');
        $level = (string) $in->code('level_code', 20);
        $streamId = $in->reference('stream_id', false, true);
        $subjectRef = (string) $in->reference('subject_ref');
        $value = (float) $in->number('coefficient', 0, 99, 0.01);
        $hours = (float) $in->number('weekly_hours', 0, 60, 0.25);
        $role = (string) $in->enum('subject_role', SubjectRoles::ALL);
        $origin = $in->enum('origin', SubjectRoles::ORIGINS, false) ?? 'custom';
        $source = $in->string('source_ref', 120, false, true);
        $validFrom = (string) $in->date('valid_from');
        $reason = (string) $in->string('reason', 255);
        $in->done();
        if ($origin === 'official' && $source === null) {
            throw HttpException::validation(['source_ref' => ['Une valeur « officielle » exige la référence du texte (sinon : origine example ou custom).']]);
        }
        $this->assertNotFrozen($tenantId, $establishmentId, $year);
        $subject = $this->store->find('se_subjects', $tenantId, $establishmentId, $subjectRef);
        if ($subject === null || (int) $subject['active'] !== 1) {
            throw HttpException::validation(['subject_ref' => ['Matière introuvable ou désactivée.']]);
        }
        if ($this->store->count('se_levels', $tenantId, $establishmentId, ['code' => $level, 'active' => 1]) === 0) {
            throw HttpException::validation(['level_code' => ['Niveau inconnu de l\'établissement.']]);
        }
        if ($streamId !== null && $this->store->find('se_streams', $tenantId, $establishmentId, $streamId) === null) {
            throw HttpException::validation(['stream_id' => ['Série ou spécialité non ouverte dans l\'établissement.']]);
        }
        $current = $this->current($tenantId, $establishmentId, $year, $level, $streamId, $subjectRef);
        if ($current !== null && (int) $current['locked'] === 1) {
            throw new HttpException(422, 'Coefficient verrouillé', 'Ce coefficient est fixé par la plateforme (texte officiel) : il ne se modifie pas dans l\'établissement (RG-ORG-4).', self::LOCKED);
        }
        $id = $this->insertVersion($principal, $establishmentId, $current, [
            'academic_year_id' => $year, 'se_subject_id' => $subjectRef, 'subject_id' => (string) $subject['subject_id'], 'level_code' => $level,
            'stream_id' => $streamId, 'coefficient' => $value, 'weekly_hours' => $hours, 'subject_role' => $role, 'origin' => $origin,
            'locked' => 0, 'source_ref' => $source, 'valid_from' => $validFrom, 'change_reason' => $reason,
        ]);

        return self::coefficient($this->store->findOrFail('se_subject_coefficients', $tenantId, $establishmentId, $id), $this->subjectLabels($tenantId, $establishmentId));
    }

    /**
     * @param array<string, mixed> $body academic_year_id, reason
     * @return array<string, mixed>
     */
    public function freezeYear(Principal $principal, string $establishmentId, array $body): array
    {
        $this->writable($principal, $establishmentId, ['secondary.settings.manage']);
        $in = new Input($body);
        $year = (string) $in->reference('academic_year_id');
        $reason = (string) $in->string('reason', 255);
        $in->done();
        $this->assertNotFrozen($principal->tenantId, $establishmentId, $year);
        $this->store->insert('se_coefficient_freezes', $principal->tenantId, $establishmentId, [
            'id' => (string) new Ulid(), 'academic_year_id' => $year, 'reason' => $reason, 'frozen_by' => $principal->userId, 'created_at' => $this->clock->now(),
        ]);
        $this->access->audit($principal, 'secondary.coefficients.frozen', 'se_coefficient_freeze', $year, null, ['reason' => $reason]);

        return (array) $this->freeze($principal->tenantId, $establishmentId, $year);
    }

    /**
     * Copie les coefficients en vigueur d'une année vers une autre (préparation de l'année suivante) : la cible ne doit rien avoir
     * pour ces lignes ; les lignes verrouillées restent verrouillées ; aucune valeur de l'année source ne change.
     *
     * @param array<string, mixed> $body from_academic_year_id, to_academic_year_id, valid_from, reason
     * @return array{copied: int, skipped: int}
     */
    public function copyYear(Principal $principal, string $establishmentId, array $body): array
    {
        $this->writable($principal, $establishmentId);
        $tenantId = $principal->tenantId;
        $in = new Input($body);
        $from = (string) $in->reference('from_academic_year_id');
        $to = (string) $in->reference('to_academic_year_id');
        $validFrom = (string) $in->date('valid_from');
        $reason = (string) $in->string('reason', 255);
        $in->done();
        if ($from === $to) {
            throw HttpException::validation(['to_academic_year_id' => ['Choisissez une autre année que l\'année source.']]);
        }
        $this->assertNotFrozen($tenantId, $establishmentId, $to);
        $copied = 0;
        $skipped = 0;
        $this->store->transaction(function () use ($principal, $establishmentId, $tenantId, $from, $to, $validFrom, $reason, &$copied, &$skipped): void {
            foreach ($this->store->select('se_subject_coefficients', $tenantId, $establishmentId, ['academic_year_id' => $from, 'superseded_at' => null], 'id', 5000) as $row) {
                $streamId = is_string($row['stream_id']) ? $row['stream_id'] : null;
                if ($this->current($tenantId, $establishmentId, $to, (string) $row['level_code'], $streamId, (string) $row['se_subject_id']) !== null) {
                    ++$skipped;
                    continue;
                }
                $this->insertVersion($principal, $establishmentId, null, [
                    'academic_year_id' => $to, 'se_subject_id' => (string) $row['se_subject_id'], 'subject_id' => (string) $row['subject_id'],
                    'level_code' => (string) $row['level_code'], 'stream_id' => $streamId, 'coefficient' => (float) $row['coefficient'],
                    'weekly_hours' => (float) $row['weekly_hours'], 'subject_role' => (string) $row['subject_role'], 'origin' => (string) $row['origin'],
                    'locked' => (int) $row['locked'], 'source_ref' => $row['source_ref'], 'valid_from' => $validFrom, 'change_reason' => $reason,
                ]);
                ++$copied;
            }
        });
        $this->access->audit($principal, 'secondary.coefficients.copied', 'se_subject_coefficient', $to, null, ['from' => $from, 'copied' => $copied]);

        return ['copied' => $copied, 'skipped' => $skipped];
    }

    /** @param list<string> $permissions */
    private function writable(Principal $principal, string $establishmentId, array $permissions = StructureService::WRITE): void
    {
        $perimeter = $this->access->require($principal, $establishmentId, $permissions);
        $this->access->assertWhole($principal, $perimeter, 'se_curriculum');
        if (!$this->profiles->forEstablishment($principal->tenantId, $establishmentId)->stored) {
            throw HttpException::conflict('Ouvrez d\'abord l\'application pour cet établissement (profil à confirmer).');
        }
    }

    private function assertNotFrozen(string $tenantId, string $establishmentId, string $year): void
    {
        if ($this->freeze($tenantId, $establishmentId, $year) !== null) {
            throw HttpException::conflict('Les coefficients de cette année sont gelés : ils ne se modifient plus et ne sont jamais recalculés. Préparez l\'année suivante par copie.');
        }
    }

    /** @return array<string, mixed>|null */
    private function freeze(string $tenantId, string $establishmentId, string $year): ?array
    {
        $row = $this->store->select('se_coefficient_freezes', $tenantId, $establishmentId, ['academic_year_id' => $year], 'id', 1)[0] ?? null;

        return $row === null ? null : [
            'academic_year_id' => (string) $row['academic_year_id'], 'reason' => (string) $row['reason'], 'frozen_by' => (string) $row['frozen_by'], 'frozen_at' => (string) $row['created_at'],
        ];
    }

    /** @return array<string, mixed>|null */
    private function current(string $tenantId, string $establishmentId, string $year, string $level, ?string $streamId, string $subjectRef): ?array
    {
        return $this->store->select('se_subject_coefficients', $tenantId, $establishmentId, [
            'academic_year_id' => $year, 'level_code' => $level, 'stream_id' => $streamId, 'se_subject_id' => $subjectRef, 'superseded_at' => null,
        ], 'version_no DESC', 1)[0] ?? null;
    }

    /**
     * @param array<string, mixed>|null $current
     * @param array<string, scalar|null> $values
     */
    private function insertVersion(Principal $principal, string $establishmentId, ?array $current, array $values): string
    {
        $tenantId = $principal->tenantId;
        $now = $this->clock->now();
        $id = (string) new Ulid();
        $this->store->transaction(function () use ($tenantId, $establishmentId, $current, $values, $now, $id, $principal): void {
            if ($current !== null) {
                $this->store->update('se_subject_coefficients', $tenantId, $establishmentId, ['superseded_at' => $now], ['id' => (string) $current['id']]);
            }
            $this->store->insert('se_subject_coefficients', $tenantId, $establishmentId, ['id' => $id] + $values + [
                'version_no' => $current === null ? 1 : (int) $current['version_no'] + 1, 'changed_by' => $principal->userId, 'superseded_at' => null, 'created_at' => $now,
            ]);
        });
        $this->access->audit($principal, 'secondary.coefficient.versioned', 'se_subject_coefficient', $id, $current, $values);

        return $id;
    }

    /** @return array<string, array{code: string, label: string}> */
    public function subjectLabels(string $tenantId, string $establishmentId): array
    {
        $labels = [];
        foreach ($this->store->select('se_subjects', $tenantId, $establishmentId, [], 'id', 1000) as $row) {
            $labels[(string) $row['id']] = ['code' => (string) $row['code'], 'label' => (string) $row['label']];
        }

        return $labels;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function subject(array $row): array
    {
        return [
            'id' => (string) $row['id'], 'code' => (string) $row['code'], 'label' => (string) $row['label'], 'label_en' => $row['label_en'],
            'subject_id' => (string) $row['subject_id'], 'active' => (int) $row['active'] === 1,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, array{code: string, label: string}> $labels
     * @return array<string, mixed>
     */
    public static function coefficient(array $row, array $labels): array
    {
        $subject = $labels[(string) $row['se_subject_id']] ?? ['code' => '', 'label' => ''];

        return [
            'id' => (string) $row['id'], 'academic_year_id' => (string) $row['academic_year_id'], 'subject_ref' => (string) $row['se_subject_id'],
            'subject_id' => (string) $row['subject_id'], 'subject_code' => $subject['code'], 'subject_label' => $subject['label'],
            'level_code' => (string) $row['level_code'], 'stream_id' => $row['stream_id'], 'coefficient' => (float) $row['coefficient'],
            'weekly_hours' => (float) $row['weekly_hours'], 'subject_role' => (string) $row['subject_role'],
            'counts_in_average' => SubjectRoles::countsInAverage((string) $row['subject_role']), 'origin' => (string) $row['origin'],
            'confirmation' => $row['origin'] === 'example' ? 'to_confirm' : ($row['origin'] === 'official' ? 'sourced' : 'establishment'),
            'locked' => (int) $row['locked'] === 1, 'source_ref' => $row['source_ref'], 'version_no' => (int) $row['version_no'],
            'valid_from' => (string) $row['valid_from'], 'change_reason' => (string) $row['change_reason'], 'changed_by' => (string) $row['changed_by'],
            'created_at' => (string) $row['created_at'], 'superseded_at' => $row['superseded_at'],
        ];
    }
}
