<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Migration\Application\Processors;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\StructureLookup;
use Scholaris\Modules\Apps\Higher\Migration\Application\Cells;
use Scholaris\Modules\Apps\Higher\Migration\Application\KindProcessor;
use Scholaris\Modules\Apps\Higher\Migration\Application\RowVerdict;
use Scholaris\Modules\Apps\Higher\Migration\Application\RunContext;
use Scholaris\Modules\Apps\Higher\Migration\Application\StructureResolver;
use Scholaris\Modules\Apps\Higher\Migration\Domain\ImportKind;
use Scholaris\Modules\Apps\Higher\Support\ScopedBySchool;
use Scholaris\Modules\Apps\Higher\Teaching\Contracts\TeachingImporter;

/**
 * Personnel enseignant : dossier enseignant IPES (statut, grade, autorisation de l'employeur) et, si la ligne donne un EC, affectation
 * (CM, TD ou TP, en heures) à un groupe. La fiche du personnel doit exister au module Personnel (colonne personne_id).
 */
final class StaffProcessor implements KindProcessor
{
    private const STATUSES = ['permanent' => 'permanent', 'vacataire' => 'vacataire', 'invite' => 'invite', 'invité' => 'invite'];

    public function __construct(
        private readonly StructureResolver $structures,
        private readonly StructureLookup $lookup,
        private readonly TeachingImporter $teaching,
        private readonly ScopedBySchool $scoped,
    ) {
    }

    public function kind(): string
    {
        return ImportKind::STAFF;
    }

    public function check(RunContext $context, int $line, array $row): RowVerdict
    {
        $person = $row['personne_id'];
        $assigns = $row['ec_code'] !== '';
        $key = 'S:' . $person . ($assigns ? '|' . strtoupper($row['filiere']) . '|' . strtoupper($row['niveau']) . '|' . strtoupper($row['groupe']) . '|' . strtoupper($row['ec_code']) . '|' . Cells::fold($row['nature']) : '');
        $verdict = new RowVerdict($line, $key, $row);
        if ($person === '') {
            $verdict->error('personne_id manquant (identifiant de la fiche du personnel).');
        }
        $status = self::STATUSES[Cells::fold($row['statut'])] ?? null;
        if ($status === null) {
            $verdict->error('Statut « ' . $row['statut'] . ' » inconnu : permanent, vacataire ou invité.');
        }
        $publicServant = Cells::yes($row['fonctionnaire']);
        $until = null;
        if ($row['autorisation_jusqua'] !== '') {
            $until = Cells::date($row['autorisation_jusqua']);
            if ($until === null) {
                $verdict->error('autorisation_jusqua : date illisible.');
            }
        }
        $departmentId = null;
        if ($row['departement'] !== '') {
            $code = $row['departement'];
            $school = null;
            if (str_contains($code, '/')) {
                [$school, $code] = array_map('trim', explode('/', $code, 2));
            }
            $found = $this->lookup->departmentsByCode($context->tenantId, $context->establishmentId, $code, $school);
            if (count($found) !== 1) {
                $verdict->error($found === [] ? 'Département « ' . $row['departement'] . ' » inconnu.' : 'Code de département ambigu : écrivez ÉCOLE/CODE.');
            } else {
                $departmentId = $found[0]['id'];
                if (!$this->scoped->covers($context->perimeter, ['school' => $found[0]['school_id'], 'department' => $departmentId])) {
                    $verdict->error('Le département est hors de votre périmètre.');
                }
            }
        } elseif (!$assigns) {
            $verdict->warn('Aucun département indiqué : dossier enseignant sans rattachement.');
        }
        if ($status !== null && $person !== '') {
            $refusal = $this->teaching->checkTeacher($context->tenantId, $person, $status, $publicServant, $row['autorisation_ref'] === '' ? null : $row['autorisation_ref'], $row['autorisation_document'] === '' ? null : $row['autorisation_document']);
            if ($refusal !== null) {
                $verdict->error($refusal);
            }
        }
        $minutes = null;
        $programId = $levelId = $groupId = null;
        if ($assigns) {
            $kind = Cells::fold($row['nature']);
            if (!in_array($kind, ['cm', 'td', 'tp'], true)) {
                $verdict->error('Nature « ' . $row['nature'] . ' » inconnue : cm, td ou tp.');
            }
            $hours = Cells::decimal($row['heures']);
            if ($hours === null || $hours <= 0) {
                $verdict->error('Heures : nombre positif attendu.');
            } else {
                $minutes = (int) round($hours * 60);
            }
            if ($row['groupe'] === '') {
                $verdict->error('Groupe manquant pour l\'affectation.');
            }
            $resolved = $this->structures->resolve($context, $row['filiere'], $row['niveau'], $row['groupe']);
            foreach ($resolved['errors'] as $error) {
                $verdict->error($error);
            }
            if ($resolved['program'] !== null && $resolved['level'] !== null && $resolved['group'] !== null && $person !== '') {
                [$programId, $levelId, $groupId] = [$resolved['program']->id, $resolved['level']->id, $resolved['group']->id];
                $refusal = $this->teaching->checkAssignment($context->tenantId, $context->establishmentId, $person, $programId, $levelId, $groupId, $context->yearId, $row['ec_code']);
                if ($refusal !== null) {
                    $verdict->error($refusal);
                }
            }
        }
        if ($context->firstSeen($key, $line) !== null) {
            if ($assigns) {
                $verdict->error('Même affectation que la ligne ' . $context->seen[$key] . ' (doublon dans le fichier).');
            }
        } elseif ($context->alreadyImported($key)) {
            $verdict->duplicate = true;
            $verdict->warn('Déjà repris par un lot précédent : ligne ignorée.');
        }
        $verdict->personId = $person === '' ? null : $person;
        $verdict->plan = [
            'status' => $status, 'public_servant' => $publicServant, 'until' => $until, 'department_id' => $departmentId, 'minutes' => $minutes,
            'program_id' => $programId, 'level_id' => $levelId, 'group_id' => $groupId, 'kind' => Cells::fold($row['nature']),
        ];

        return $verdict;
    }

    public function apply(RunContext $context, array $verdicts): array
    {
        $refs = [];
        foreach ($verdicts as $verdict) {
            $plan = $verdict->plan;
            $row = $verdict->row;
            try {
                $teacher = $this->teaching->importTeacher(
                    $context->tenantId,
                    $context->establishmentId,
                    $context->actorId,
                    $row['personne_id'],
                    is_string($plan['department_id']) ? $plan['department_id'] : null,
                    (string) $plan['status'],
                    $row['grade'] === '' ? null : $row['grade'],
                    $plan['public_servant'] === true,
                    $row['autorisation_ref'] === '' ? null : $row['autorisation_ref'],
                    $row['autorisation_document'] === '' ? null : $row['autorisation_document'],
                    is_string($plan['until']) ? $plan['until'] : null,
                );
                $assignment = null;
                if ($row['ec_code'] !== '') {
                    $assignment = $this->teaching->importAssignment(
                        $context->tenantId,
                        $context->establishmentId,
                        $context->actorId,
                        $row['personne_id'],
                        (string) $plan['program_id'],
                        (string) $plan['level_id'],
                        (string) $plan['group_id'],
                        $context->yearId,
                        $row['ec_code'],
                        (string) $plan['kind'],
                        (int) $plan['minutes'],
                    );
                }
            } catch (HttpException $e) {
                throw AtLine::wrap($verdict->line, $e);
            }
            $refs[$verdict->line] = [
                'person_id' => $row['personne_id'], 'profile_id' => $teacher['profile_id'], 'profile_created' => $teacher['created'],
                'assignment_id' => $assignment['assignment_id'] ?? null, 'assignment_created' => $assignment['created'] ?? false,
            ];
        }

        return $refs;
    }

    public function revert(RunContext $context, array $refs, string $reason): void
    {
        if (($refs['assignment_created'] ?? false) === true && is_string($refs['assignment_id'] ?? null)) {
            $this->teaching->revertAssignment($context->tenantId, $context->establishmentId, $context->actorId, $refs['assignment_id']);
        }
        if (($refs['profile_created'] ?? false) === true && is_string($refs['profile_id'] ?? null)) {
            $this->teaching->revertTeacher($context->tenantId, $context->establishmentId, $refs['profile_id']);
        }
    }

    public function revertRefusal(): ?string
    {
        return null;
    }
}
