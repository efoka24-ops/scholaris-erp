<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Grades\Infrastructure;

use Scholaris\Modules\Apps\Higher\Academic\Infrastructure\Store;
use Scholaris\Modules\Apps\Higher\Curriculum\Contracts\GradeProbe;
use Scholaris\Modules\Apps\Higher\Grades\Application\AssessmentService;
use Scholaris\Modules\Apps\Higher\Grades\Application\GradesSettings;
use Scholaris\Modules\Apps\Higher\Grades\Contracts\GradeBook;
use Scholaris\Modules\Apps\Higher\Grades\Domain\IpesDefaults;

/**
 * Carnet de notes IPES pour les autres sous-modules (Contracts\GradeBook) et sonde de notes de Curriculum (RG-MAQ-1,
 * `higher.curriculum.grade_probes.grades`) : une maquette « porte des notes » dès qu'une cellule d'une de ses évaluations est saisie.
 */
final class PdoGradeBook implements GradeBook, GradeProbe
{
    public function __construct(
        private readonly Store $store,
        private readonly GradesSettings $settings,
    ) {
    }

    public function assessmentsOf(string $tenantId, string $establishmentId, string $curriculumId, ?int $semesterOrdinal = null): array
    {
        $where = ['curriculum_id' => $curriculumId];
        if ($semesterOrdinal !== null) {
            $where['semester_ordinal'] = $semesterOrdinal;
        }

        return array_map(static fn (array $r): array => [
            'id' => (string) $r['id'], 'element_id' => (string) $r['element_id'], 'unit_id' => (string) $r['unit_id'],
            'group_id' => $r['group_id'] === null ? null : (string) $r['group_id'], 'kind' => (string) $r['kind'],
            'exam_session' => (string) $r['exam_session'], 'max_score' => (float) $r['max_score'], 'weight_percent' => (int) $r['weight_percent'],
            'status' => (string) $r['status'], 'semester_ordinal' => (int) $r['semester_ordinal'],
        ], $this->store->select(AssessmentService::TABLE, $tenantId, $establishmentId, $where, 'element_code, kind, id', 5000));
    }

    public function allLocked(string $tenantId, string $establishmentId, string $curriculumId, ?int $semesterOrdinal = null): bool
    {
        $rows = $this->store->select(AssessmentService::TABLE, $tenantId, $establishmentId, ['curriculum_id' => $curriculumId] + ($semesterOrdinal === null ? [] : ['semester_ordinal' => $semesterOrdinal]), 'id', 5000);
        if ($rows === []) {
            return false;
        }
        foreach ($rows as $row) {
            $lifecycle = $this->settings->lifecycle($tenantId, $establishmentId, (string) $row['school_id']);
            $target = in_array(IpesDefaults::LOCKED, $lifecycle->states, true) ? IpesDefaults::LOCKED : $lifecycle->published;
            if (!$lifecycle->reached((string) $row['status'], $target)) {
                return false;
            }
        }

        return true;
    }

    public function marksOf(string $tenantId, string $establishmentId, string $assessmentId): array
    {
        return array_map(static fn (array $m): array => [
            'person_id' => (string) $m['person_id'], 'score' => $m['score'] === null ? null : (float) $m['score'],
            'absence' => $m['absence_code'] === null ? null : (string) $m['absence_code'], 'version' => (int) $m['version'],
        ], $this->store->select(AssessmentService::MARKS, $tenantId, $establishmentId, ['assessment_id' => $assessmentId], 'person_id', 5000));
    }

    public function elementComplete(string $tenantId, string $establishmentId, string $elementId, ?string $groupId = null): bool
    {
        $rows = $this->store->select(AssessmentService::TABLE, $tenantId, $establishmentId, ['element_id' => $elementId, 'exam_session' => 'normal'], 'id', 500);
        $found = false;
        foreach ($rows as $row) {
            if ($groupId !== null && $row['group_id'] !== null && $row['group_id'] !== $groupId) {
                continue;
            }
            $found = true;
            if ($this->settings->lifecycle($tenantId, $establishmentId, (string) $row['school_id'])->isEditable((string) $row['status'])) {
                return false;
            }
            foreach ($this->marksOf($tenantId, $establishmentId, (string) $row['id']) as $mark) {
                if ($mark['score'] === null && $mark['absence'] === null) {
                    return false;
                }
            }
        }

        return $found;
    }

    public function hasGrades(string $tenantId, string $establishmentId, string $curriculumId): bool
    {
        foreach ($this->store->select(AssessmentService::TABLE, $tenantId, $establishmentId, ['curriculum_id' => $curriculumId], 'id', 5000) as $row) {
            if ($this->store->count(AssessmentService::MARKS, $tenantId, $establishmentId, ['assessment_id' => $row['id']]) > 0
                && $this->store->select(AssessmentService::MARKS, $tenantId, $establishmentId, ['assessment_id' => $row['id']], 'id', 1, 'score IS NOT NULL OR absence_code IS NOT NULL') !== []) {
                return true;
            }
        }

        return false;
    }
}
