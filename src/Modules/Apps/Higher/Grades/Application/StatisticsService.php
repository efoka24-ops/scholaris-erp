<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Grades\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Higher\Academic\Infrastructure\Store;
use Scholaris\Modules\Apps\Higher\Grades\Domain\EcMark;
use Scholaris\Modules\Apps\Higher\Grades\Domain\Statistics;

/**
 * Statistiques par évaluation et par EC (EX-NOT-09, T272) : moyenne, écart-type, minimum, maximum, taux de réussite, distribution,
 * avec ventilation par groupe. Notes ramenées sur 20 ; effets d'absence appliqués (zéro compté, absence exclue ou à repasser ignorée).
 * Suivi de la saisie (EX-NOT-08) : évaluations saisies, soumises, en retard.
 */
final class StatisticsService
{
    public function __construct(
        private readonly Store $store,
        private readonly MarkPolicy $policy,
        private readonly AssessmentService $assessments,
        private readonly GradesSettings $settings,
        private readonly GradesClock $clock,
    ) {
    }

    /** @return array<string, mixed> */
    public function forAssessment(Principal $principal, string $establishmentId, string $assessmentId): array
    {
        $tenantId = $principal->tenantId;
        $row = $this->assessments->find($tenantId, $establishmentId, $assessmentId);
        $this->policy->assertReadable($principal, $establishmentId, $row);
        $effects = $this->settings->absenceEffects($tenantId, $establishmentId, (string) $row['school_id']);
        $all = [];
        $byGroup = [];
        $absences = [];
        foreach ($this->store->select(AssessmentService::MARKS, $tenantId, $establishmentId, ['assessment_id' => $assessmentId], 'id', 5000) as $mark) {
            if ($mark['absence_code'] !== null) {
                $absences[(string) $mark['absence_code']] = ($absences[(string) $mark['absence_code']] ?? 0) + 1;
            }
            $value = EcMark::onTwenty($mark['score'] === null ? null : (float) $mark['score'], $mark['absence_code'], (float) $row['max_score'], $effects);
            if ($value === null) {
                continue;
            }
            $all[] = $value;
            $byGroup[(string) ($mark['group_id'] ?? '')][] = $value;
        }

        return ['assessment' => AssessmentService::resource($row), 'absences' => $absences] + $this->series($all, $byGroup);
    }

    /**
     * Notes d'EC (F1) de chaque étudiant à partir de toutes les évaluations de l'EC, puis statistiques.
     *
     * @return array<string, mixed>
     */
    public function forElement(Principal $principal, string $establishmentId, string $elementId, ?string $groupId = null): array
    {
        $tenantId = $principal->tenantId;
        $rows = $this->store->select(AssessmentService::TABLE, $tenantId, $establishmentId, ['element_id' => $elementId], 'created_at', 200);
        $readable = [];
        foreach ($rows as $row) {
            try {
                $this->policy->assertReadable($principal, $establishmentId, $row);
                $readable[] = $row;
            } catch (HttpException) {
                continue;
            }
        }
        if ($readable === []) {
            throw HttpException::notFound('Aucune évaluation lisible pour cet élément constitutif.');
        }
        $cells = [];
        $groups = [];
        foreach ($readable as $row) {
            $effects = $this->settings->absenceEffects($tenantId, $establishmentId, (string) $row['school_id']);
            foreach ($this->store->select(AssessmentService::MARKS, $tenantId, $establishmentId, ['assessment_id' => $row['id']], 'id', 5000) as $mark) {
                if ($groupId !== null && $mark['group_id'] !== $groupId) {
                    continue;
                }
                $person = (string) $mark['person_id'];
                $groups[$person] = (string) ($mark['group_id'] ?? '');
                $cells[$person][] = ['kind' => (string) $row['kind'], 'weight_percent' => (int) $row['weight_percent'],
                    'on_twenty' => EcMark::onTwenty($mark['score'] === null ? null : (float) $mark['score'], $mark['absence_code'], (float) $row['max_score'], $effects),
                    'pending' => EcMark::pending($mark['absence_code'], $effects)];
            }
        }
        $all = [];
        $byGroup = [];
        foreach ($cells as $person => $list) {
            $mark = EcMark::compute($list);
            if ($mark !== null) {
                $all[] = $mark;
                $byGroup[$groups[$person]][] = $mark;
            }
        }
        $first = $readable[0];

        return [
            'element_id' => $elementId, 'element_code' => (string) $first['element_code'], 'element_title' => (string) $first['element_title'],
            'assessments' => array_map(AssessmentService::resource(...), $readable), 'students' => count($cells),
        ] + $this->series($all, $byGroup);
    }

    /**
     * Suivi de la saisie (EX-NOT-08) : par évaluation visible, cellules saisies, état, retard.
     *
     * @param array<string, string> $filters
     * @return array<string, mixed>
     */
    public function progress(Principal $principal, string $establishmentId, array $filters, ?string $school): array
    {
        $tenantId = $principal->tenantId;
        $today = $this->clock->today();
        $lines = [];
        $summary = ['assessments' => 0, 'complete' => 0, 'overdue' => 0, 'by_status' => []];
        foreach ($this->assessments->visible($principal, $establishmentId, $filters, $school) as $row) {
            $total = $this->store->count(AssessmentService::MARKS, $tenantId, $establishmentId, ['assessment_id' => $row['id']]);
            $entered = $this->assessments->enteredCount($tenantId, $establishmentId, (string) $row['id']);
            $editable = $this->settings->lifecycle($tenantId, $establishmentId, (string) $row['school_id'])->isEditable((string) $row['status']);
            $overdue = $editable && $row['entry_due_on'] !== null && (string) $row['entry_due_on'] < $today;
            $lines[] = AssessmentService::resource($row) + ['entered' => $entered, 'total' => $total, 'overdue' => $overdue];
            ++$summary['assessments'];
            $summary['complete'] += $total > 0 && $entered === $total ? 1 : 0;
            $summary['overdue'] += $overdue ? 1 : 0;
            $summary['by_status'][(string) $row['status']] = ($summary['by_status'][(string) $row['status']] ?? 0) + 1;
        }

        return ['summary' => $summary, 'lines' => $lines];
    }

    /**
     * @param list<float> $all
     * @param array<string, list<float>> $byGroup
     * @return array<string, mixed>
     */
    private function series(array $all, array $byGroup): array
    {
        ksort($byGroup);
        $groups = [];
        foreach ($byGroup as $group => $values) {
            $groups[] = ['group_id' => $group === '' ? null : $group] + Statistics::of($values);
        }

        return ['statistics' => Statistics::of($all), 'groups' => $groups];
    }
}
