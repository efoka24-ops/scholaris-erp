<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Migration\Application\Processors;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Apps\Higher\Enrolment\Contracts\StudentDirectory;
use Scholaris\Modules\Apps\Higher\Grades\Contracts\MarkImporter;
use Scholaris\Modules\Apps\Higher\Migration\Application\Cells;
use Scholaris\Modules\Apps\Higher\Migration\Application\KindProcessor;
use Scholaris\Modules\Apps\Higher\Migration\Application\RowVerdict;
use Scholaris\Modules\Apps\Higher\Migration\Application\RunContext;
use Scholaris\Modules\Apps\Higher\Migration\Application\StructureResolver;
use Scholaris\Modules\Apps\Higher\Migration\Domain\ImportKind;

/**
 * Notes de l'année EN COURS seulement. Une évaluation de reprise (EC, groupe, type cc/tp/exam) est créée à l'état « verrouillé » avec le
 * motif « reprise » ; jamais publiée. L'étudiant doit être inscrit au niveau cette année. Une note vide est refusée (saisir un code d'absence).
 */
final class MarksProcessor implements KindProcessor
{
    public const REASON = 'Reprise de données';

    public function __construct(
        private readonly StructureResolver $structures,
        private readonly StudentDirectory $students,
        private readonly MarkImporter $marks,
    ) {
    }

    public function kind(): string
    {
        return ImportKind::MARKS;
    }

    public function check(RunContext $context, int $line, array $row): RowVerdict
    {
        $matricule = strtoupper($row['matricule']);
        $evaluation = Cells::fold($row['evaluation']);
        $verdict = new RowVerdict($line, 'N:' . $matricule . '|' . strtoupper($row['filiere']) . '|' . strtoupper($row['niveau']) . '|' . strtoupper($row['ec_code']) . '|' . $evaluation, $row);
        if ($matricule === '') {
            $verdict->error('Matricule manquant.');
        }
        if ($row['note'] === '') {
            $verdict->error('Note ou code d\'absence manquant.');
        }
        $resolved = $this->structures->resolve($context, $row['filiere'], $row['niveau'], $row['groupe']);
        foreach ($resolved['errors'] as $error) {
            $verdict->error($error);
        }
        $program = $resolved['program'];
        $level = $resolved['level'];
        $group = $resolved['group'];
        $value = ['score' => null, 'absence' => null];
        if ($program !== null && $row['note'] !== '') {
            try {
                $value = $this->marks->normalize($context->tenantId, $context->establishmentId, $program->id, $row['note']);
            } catch (HttpException $e) {
                $verdict->error(implode(' ', $e->errors['score'] ?? [$e->getMessage()]));
            }
        }
        if ($program !== null && $level !== null && $resolved['errors'] === []) {
            $targetKey = 'target|' . $level->id . '|' . ($group->id ?? '') . '|' . strtoupper($row['ec_code']) . '|' . $evaluation;
            if (!array_key_exists($targetKey, $context->cache)) {
                $context->cache[$targetKey] = $this->marks->checkTarget($context->tenantId, $context->establishmentId, $program->id, $level->id, $group?->id, $context->yearId, $row['ec_code'], $evaluation);
            }
            if (is_string($context->cache[$targetKey])) {
                $verdict->error($context->cache[$targetKey]);
            }
        }
        $enrolmentId = null;
        $student = $matricule === '' ? null : $this->students->byMatricule($context->tenantId, $context->establishmentId, $matricule);
        if ($matricule !== '' && $student === null) {
            $verdict->error('Matricule inconnu : reprenez d\'abord les étudiants.');
        }
        if ($student !== null && $level !== null) {
            $verdict->personId = $student->personId;
            foreach ($this->students->enrolmentsOf($context->tenantId, $context->establishmentId, $student->personId) as $enrolment) {
                if ($enrolment->academicYearId === $context->yearId && $enrolment->levelId === $level->id) {
                    $enrolmentId = $enrolment->id;
                }
            }
            if ($enrolmentId === null) {
                $verdict->error('L\'étudiant n\'est pas inscrit dans ce niveau cette année.');
            }
        }
        if ($context->firstSeen($verdict->key, $line) !== null) {
            $verdict->error('Même note que la ligne ' . $context->seen[$verdict->key] . ' (doublon dans le fichier).');
        } elseif ($context->alreadyImported($verdict->key)) {
            $verdict->duplicate = true;
            $verdict->warn('Déjà reprise par un lot précédent : ligne ignorée.');
        }
        $verdict->plan = [
            'person_id' => $student?->personId, 'enrolment_id' => $enrolmentId, 'program_id' => $program?->id, 'level_id' => $level?->id,
            'group_id' => $group?->id, 'score' => $value['score'], 'absence' => $value['absence'], 'evaluation' => $evaluation,
        ];

        return $verdict;
    }

    public function apply(RunContext $context, array $verdicts): array
    {
        $groups = [];
        foreach ($verdicts as $verdict) {
            $plan = $verdict->plan;
            $groups[implode('|', [$plan['program_id'], $plan['level_id'], $plan['group_id'] ?? '', strtoupper($verdict->row['ec_code']), $plan['evaluation']])][] = $verdict;
        }
        $refs = [];
        foreach ($groups as $members) {
            $first = $members[0];
            $plan = $first->plan;
            $cells = [];
            foreach ($members as $member) {
                $cells[] = [
                    'person_id' => (string) $member->plan['person_id'], 'enrolment_id' => is_string($member->plan['enrolment_id']) ? $member->plan['enrolment_id'] : null,
                    'score' => is_float($member->plan['score']) ? $member->plan['score'] : null, 'absence' => is_string($member->plan['absence']) ? $member->plan['absence'] : null,
                ];
            }
            try {
                $result = $this->marks->importAssessment(
                    $context->tenantId,
                    $context->establishmentId,
                    $context->actorId,
                    (string) $plan['program_id'],
                    (string) $plan['level_id'],
                    is_string($plan['group_id']) ? $plan['group_id'] : null,
                    $context->yearId,
                    $first->row['ec_code'],
                    (string) $plan['evaluation'],
                    $cells,
                    self::REASON,
                );
            } catch (HttpException $e) {
                throw AtLine::wrap($first->line, $e);
            }
            foreach ($members as $member) {
                $refs[$member->line] = ['person_id' => $member->plan['person_id'], 'assessment_id' => $result['assessment_id'], 'assessment_created' => $result['created']];
            }
        }

        return $refs;
    }

    public function revert(RunContext $context, array $refs, string $reason): void
    {
        if (($refs['assessment_created'] ?? false) === true && is_string($refs['assessment_id'] ?? null)) {
            $this->marks->revertAssessment($context->tenantId, $context->establishmentId, $context->actorId, $refs['assessment_id']);
        }
    }

    public function revertRefusal(): ?string
    {
        return null;
    }
}
