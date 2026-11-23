<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Grades\Infrastructure;

use Scholaris\Modules\Apps\Higher\Grades\Contracts\GradingStatistics;
use Scholaris\Modules\Apps\Higher\Support\AggregateReader;
use Scholaris\Modules\Apps\Higher\Support\Perimeter;
use Scholaris\Modules\Apps\Higher\Support\StatisticsFilter;

final class PdoGradingStatistics implements GradingStatistics
{
    private const SCOPE = ['school' => 'a.school_id', 'department' => 'a.department_id', 'program' => 'a.program_id', 'level' => 'a.level_id', 'group' => 'a.group_id'];

    public function __construct(private readonly AggregateReader $reader)
    {
    }

    public function progress(string $tenantId, string $establishmentId, Perimeter $perimeter, StatisticsFilter $filter, string $today): array
    {
        [$where, $params] = $this->where($tenantId, $establishmentId, $perimeter, $filter);
        $rows = $this->reader->fetch(
            'SELECT t.school_id, t.program_id, t.unit_id, t.element_id, MAX(t.element_code) AS element_code, MAX(t.element_title) AS element_title, '
            . 'COUNT(*) AS assessments, '
            . "SUM(CASE WHEN t.status = 'draft' THEN 1 ELSE 0 END) AS draft, SUM(CASE WHEN t.status = 'submitted' THEN 1 ELSE 0 END) AS submitted, "
            . "SUM(CASE WHEN t.status = 'validated' THEN 1 ELSE 0 END) AS validated, SUM(CASE WHEN t.status = 'locked' THEN 1 ELSE 0 END) AS locked, "
            . "SUM(CASE WHEN t.status = 'published' THEN 1 ELSE 0 END) AS published, "
            . "SUM(CASE WHEN t.status = 'draft' AND t.entry_due_on IS NOT NULL AND t.entry_due_on < ? THEN 1 ELSE 0 END) AS overdue, "
            . 'SUM(t.expected) AS expected, SUM(t.entered) AS entered FROM ('
            . 'SELECT a.school_id, a.program_id, a.unit_id, a.element_id, a.element_code, a.element_title, a.status, a.entry_due_on, '
            . '(SELECT COUNT(*) FROM hi_grade_marks m WHERE m.tenant_id = a.tenant_id AND m.assessment_id = a.id) AS expected, '
            . '(SELECT COUNT(*) FROM hi_grade_marks m WHERE m.tenant_id = a.tenant_id AND m.assessment_id = a.id AND (m.score IS NOT NULL OR m.absence_code IS NOT NULL)) AS entered '
            . 'FROM hi_grade_assessments a WHERE ' . $where
            . ') t GROUP BY t.school_id, t.program_id, t.unit_id, t.element_id ORDER BY t.school_id, t.program_id, element_code',
            [$today, ...$params],
        );

        return array_map(static fn (array $r): array => [
            'school_id' => (string) $r['school_id'], 'program_id' => (string) $r['program_id'], 'unit_id' => (string) $r['unit_id'],
            'element_id' => (string) $r['element_id'], 'element_code' => (string) $r['element_code'], 'element_title' => (string) $r['element_title'],
            'assessments' => (int) $r['assessments'], 'draft' => (int) $r['draft'], 'submitted' => (int) $r['submitted'], 'validated' => (int) $r['validated'],
            'locked' => (int) $r['locked'], 'published' => (int) $r['published'], 'overdue' => (int) $r['overdue'],
            'expected' => (int) $r['expected'], 'entered' => (int) $r['entered'],
        ], $rows);
    }

    public function success(string $tenantId, string $establishmentId, Perimeter $perimeter, StatisticsFilter $filter, string $session): array
    {
        [$where, $params] = $this->where($tenantId, $establishmentId, $perimeter, $filter);
        $rows = $this->reader->fetch(
            'SELECT p.school_id, p.program_id, p.level_id, p.unit_id, p.element_id, MAX(p.element_code) AS element_code, MAX(p.element_title) AS element_title, '
            . 'COUNT(*) AS students, SUM(CASE WHEN p.average >= 10 THEN 1 ELSE 0 END) AS passed FROM ('
            . 'SELECT a.school_id, a.program_id, a.level_id, a.unit_id, a.element_id, MAX(a.element_code) AS element_code, MAX(a.element_title) AS element_title, m.person_id, '
            . 'SUM(m.score * 20.0 * a.weight_percent / a.max_score) / SUM(a.weight_percent) AS average '
            . 'FROM hi_grade_marks m JOIN hi_grade_assessments a ON a.tenant_id = m.tenant_id AND a.id = m.assessment_id '
            . "WHERE m.score IS NOT NULL AND a.status IN ('locked', 'published') AND a.exam_session = ? AND " . $where
            . ' GROUP BY a.school_id, a.program_id, a.level_id, a.unit_id, a.element_id, m.person_id'
            . ') p GROUP BY p.school_id, p.program_id, p.level_id, p.unit_id, p.element_id ORDER BY p.school_id, p.program_id, element_code',
            [$session, ...$params],
        );

        return array_map(static fn (array $r): array => [
            'school_id' => (string) $r['school_id'], 'program_id' => (string) $r['program_id'], 'level_id' => (string) $r['level_id'],
            'unit_id' => (string) $r['unit_id'], 'element_id' => (string) $r['element_id'], 'element_code' => (string) $r['element_code'],
            'element_title' => (string) $r['element_title'], 'students' => (int) $r['students'], 'passed' => (int) $r['passed'],
        ], $rows);
    }

    /** @return array{0: string, 1: list<mixed>} */
    private function where(string $tenantId, string $establishmentId, Perimeter $perimeter, StatisticsFilter $filter): array
    {
        [$scope, $scopeParams] = $this->reader->scope($perimeter, self::SCOPE);
        $where = 'a.tenant_id = ? AND a.establishment_id = ? AND ' . $scope;
        $params = [$tenantId, $establishmentId, ...$scopeParams];
        if ($filter->programId !== null) {
            $where .= ' AND a.program_id = ?';
            $params[] = $filter->programId;
        }
        if ($filter->academicYearId !== null) {
            $where .= ' AND a.academic_year_id = ?';
            $params[] = $filter->academicYearId;
        }

        return [$where, $params];
    }
}
