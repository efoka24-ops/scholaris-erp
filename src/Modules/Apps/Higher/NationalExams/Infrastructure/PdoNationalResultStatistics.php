<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\NationalExams\Infrastructure;

use Scholaris\Modules\Apps\Higher\NationalExams\Contracts\NationalResultStatistics;
use Scholaris\Modules\Apps\Higher\Support\AggregateReader;
use Scholaris\Modules\Apps\Higher\Support\Perimeter;
use Scholaris\Modules\Apps\Higher\Support\StatisticsFilter;

final class PdoNationalResultStatistics implements NationalResultStatistics
{
    public function __construct(private readonly AggregateReader $reader)
    {
    }

    public function results(string $tenantId, string $establishmentId, Perimeter $perimeter, StatisticsFilter $filter): array
    {
        [$scope, $scopeParams] = $this->reader->scope($perimeter, ['school' => 'c.school_id', 'department' => 'c.department_id', 'program' => 'c.program_id', 'level' => 'c.level_id', 'group' => 'c.group_id']);
        $where = "c.tenant_id = ? AND c.establishment_id = ? AND c.status <> 'withdrawn' AND " . $scope;
        $params = [$tenantId, $establishmentId, ...$scopeParams];
        if ($filter->programId !== null) {
            $where .= ' AND c.program_id = ?';
            $params[] = $filter->programId;
        }
        if ($filter->academicYearId !== null) {
            $where .= ' AND s.academic_year_id = ?';
            $params[] = $filter->academicYearId;
        }
        $rows = $this->reader->fetch(
            'SELECT s.exam_type, c.school_id, c.program_id, s.academic_year_id, COUNT(*) AS candidates, '
            . 'SUM(CASE WHEN c.result_code IS NOT NULL THEN 1 ELSE 0 END) AS resulted, '
            . "SUM(CASE WHEN c.result_code = 'passed' THEN 1 ELSE 0 END) AS passed, SUM(CASE WHEN c.result_code = 'failed' THEN 1 ELSE 0 END) AS failed, "
            . "SUM(CASE WHEN c.result_code = 'absent' THEN 1 ELSE 0 END) AS absent "
            . 'FROM hi_national_candidates c JOIN hi_national_sessions s ON s.tenant_id = c.tenant_id AND s.id = c.session_id WHERE ' . $where
            . ' GROUP BY s.exam_type, c.school_id, c.program_id, s.academic_year_id ORDER BY s.exam_type, c.school_id, c.program_id, s.academic_year_id',
            $params,
        );

        return array_map(static fn (array $r): array => [
            'exam_type' => (string) $r['exam_type'], 'school_id' => (string) $r['school_id'], 'program_id' => (string) $r['program_id'],
            'academic_year_id' => (string) $r['academic_year_id'], 'candidates' => (int) $r['candidates'], 'resulted' => (int) $r['resulted'],
            'passed' => (int) $r['passed'], 'failed' => (int) $r['failed'], 'absent' => (int) $r['absent'],
        ], $rows);
    }
}
