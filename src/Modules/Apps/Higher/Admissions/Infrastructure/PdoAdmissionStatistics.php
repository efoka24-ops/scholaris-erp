<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Admissions\Infrastructure;

use Scholaris\Modules\Apps\Higher\Admissions\Contracts\AdmissionStatistics;
use Scholaris\Modules\Apps\Higher\Support\AggregateReader;
use Scholaris\Modules\Apps\Higher\Support\Perimeter;
use Scholaris\Modules\Apps\Higher\Support\StatisticsFilter;

final class PdoAdmissionStatistics implements AdmissionStatistics
{
    public function __construct(private readonly AggregateReader $reader)
    {
    }

    public function funnel(string $tenantId, string $establishmentId, Perimeter $perimeter, StatisticsFilter $filter): array
    {
        [$scope, $scopeParams] = $this->reader->scope($perimeter, ['school' => 'school_id', 'department' => 'department_id', 'program' => 'program_id', 'level' => 'level_id']);
        $where = 'tenant_id = ? AND establishment_id = ? AND status <> ? AND ' . $scope;
        $params = [$tenantId, $establishmentId, 'draft', ...$scopeParams];
        if ($filter->programId !== null) {
            $where .= ' AND program_id = ?';
            $params[] = $filter->programId;
        }
        if ($filter->academicYearId !== null) {
            $where .= ' AND academic_year_id = ?';
            $params[] = $filter->academicYearId;
        }
        if ($filter->from !== null) {
            $where .= ' AND submitted_at >= ?';
            $params[] = $filter->from;
        }
        if ($filter->to !== null) {
            $where .= ' AND submitted_at < ?';
            $params[] = $filter->to . 'T99';
        }
        $rows = $this->reader->fetch(
            'SELECT school_id, program_id, COUNT(*) AS applications, '
            . "SUM(CASE WHEN status IN ('admitted', 'conditional', 'enrolled') THEN 1 ELSE 0 END) AS admitted, "
            . "SUM(CASE WHEN status = 'enrolled' THEN 1 ELSE 0 END) AS enrolled "
            . 'FROM hi_applications WHERE ' . $where . ' GROUP BY school_id, program_id ORDER BY school_id, program_id',
            $params,
        );

        return array_map(static fn (array $r): array => [
            'school_id' => (string) $r['school_id'], 'program_id' => (string) $r['program_id'],
            'applications' => (int) $r['applications'], 'admitted' => (int) $r['admitted'], 'enrolled' => (int) $r['enrolled'],
        ], $rows);
    }
}
