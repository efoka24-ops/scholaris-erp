<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Attendance\Infrastructure;

use Scholaris\Modules\Apps\Higher\Attendance\Contracts\AssiduityStatistics;
use Scholaris\Modules\Apps\Higher\Support\AggregateReader;
use Scholaris\Modules\Apps\Higher\Support\Perimeter;
use Scholaris\Modules\Apps\Higher\Support\StatisticsFilter;
use Scholaris\Modules\Socle\Attendance\Contracts\UnitAttendanceTotals;

/**
 * Totaux d'appel du socle (UnitAttendanceTotals, une lecture) restreints au périmètre : le groupe de la séance doit y figurer.
 */
final class PdoAssiduityStatistics implements AssiduityStatistics
{
    public function __construct(
        private readonly UnitAttendanceTotals $totals,
        private readonly AggregateReader $reader,
    ) {
    }

    public function byGroupElement(string $tenantId, string $establishmentId, Perimeter $perimeter, StatisticsFilter $filter): array
    {
        $rows = [];
        foreach ($this->totals->byStructureUnit($tenantId, $establishmentId, $filter->from, $filter->to) as $row) {
            if (!$this->reader->covers($perimeter, ['group' => $row['structure_id']])) {
                continue;
            }
            $rows[] = [
                'group_id' => $row['structure_id'], 'element_id' => $row['course_unit_id'], 'label' => $row['label'], 'sessions' => $row['sessions'],
                'records' => $row['records'], 'present' => $row['present'], 'late' => $row['late'], 'absent' => $row['absent'], 'unjustified' => $row['unjustified'],
            ];
        }

        return $rows;
    }
}
