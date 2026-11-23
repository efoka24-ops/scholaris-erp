<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Vacations\Infrastructure;

use Scholaris\Modules\Apps\Higher\Support\AggregateReader;
use Scholaris\Modules\Apps\Higher\Support\Perimeter;
use Scholaris\Modules\Apps\Higher\Support\StatisticsFilter;
use Scholaris\Modules\Apps\Higher\Vacations\Contracts\VacationStatistics;

final class PdoVacationStatistics implements VacationStatistics
{
    public function __construct(private readonly AggregateReader $reader)
    {
    }

    public function byDepartmentMonth(string $tenantId, string $establishmentId, Perimeter $perimeter, StatisticsFilter $filter): array
    {
        [$scope, $scopeParams] = $this->reader->scope($perimeter, ['school' => 'school_id', 'department' => 'department_id']);
        $where = "tenant_id = ? AND establishment_id = ? AND status IN ('school_validated', 'approved', 'paid') AND " . $scope;
        $params = [$tenantId, $establishmentId, ...$scopeParams];
        if ($filter->from !== null) {
            $where .= ' AND period_month >= ?';
            $params[] = substr($filter->from, 0, 7);
        }
        if ($filter->to !== null) {
            $where .= ' AND period_month <= ?';
            $params[] = substr($filter->to, 0, 7);
        }
        $rows = $this->reader->fetch(
            'SELECT school_id, department_id, period_month, COUNT(*) AS statements, '
            . 'SUM(cm_minutes + td_minutes + tp_minutes + other_minutes) AS minutes, SUM(hetd_milli) AS hetd_milli, '
            . "SUM(gross_amount) AS gross_xaf, SUM(net_amount) AS net_xaf, SUM(CASE WHEN status = 'paid' THEN net_amount ELSE 0 END) AS paid_xaf "
            . 'FROM hi_vacation_statements WHERE ' . $where
            . ' GROUP BY school_id, department_id, period_month ORDER BY school_id, department_id, period_month',
            $params,
        );

        return array_map(static fn (array $r): array => [
            'school_id' => (string) $r['school_id'], 'department_id' => (string) $r['department_id'], 'month' => (string) $r['period_month'],
            'statements' => (int) $r['statements'], 'minutes' => (int) $r['minutes'], 'hetd_milli' => (int) $r['hetd_milli'],
            'gross_xaf' => (int) $r['gross_xaf'], 'net_xaf' => (int) $r['net_xaf'], 'paid_xaf' => (int) $r['paid_xaf'],
        ], $rows);
    }
}
