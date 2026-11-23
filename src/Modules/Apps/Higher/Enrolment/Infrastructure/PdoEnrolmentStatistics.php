<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Enrolment\Infrastructure;

use Scholaris\Modules\Apps\Higher\Enrolment\Contracts\EnrolmentStatistics;
use Scholaris\Modules\Apps\Higher\Support\AggregateReader;
use Scholaris\Modules\Apps\Higher\Support\Perimeter;
use Scholaris\Modules\Apps\Higher\Support\StatisticsFilter;

final class PdoEnrolmentStatistics implements EnrolmentStatistics
{
    public function __construct(private readonly AggregateReader $reader)
    {
    }

    public function headcount(string $tenantId, string $establishmentId, Perimeter $perimeter, StatisticsFilter $filter): array
    {
        [$where, $params] = $this->where($tenantId, $establishmentId, $perimeter, $filter);
        $rows = $this->reader->fetch(
            'SELECT school_id, program_id, level_id, COUNT(*) AS enrolled FROM hi_enrolments WHERE ' . $where
            . ' GROUP BY school_id, program_id, level_id ORDER BY school_id, program_id, level_id',
            $params,
        );

        return array_map(static fn (array $r): array => [
            'school_id' => (string) $r['school_id'], 'program_id' => (string) $r['program_id'],
            'level_id' => (string) $r['level_id'], 'enrolled' => (int) $r['enrolled'],
        ], $rows);
    }

    public function enrolledPersons(string $tenantId, string $establishmentId, Perimeter $perimeter, StatisticsFilter $filter): array
    {
        [$where, $params] = $this->where($tenantId, $establishmentId, $perimeter, $filter);
        $rows = $this->reader->fetch('SELECT person_id, program_id, level_id FROM hi_enrolments WHERE ' . $where . ' ORDER BY id', $params);

        return array_map(static fn (array $r): array => [
            'person_id' => (string) $r['person_id'], 'program_id' => (string) $r['program_id'], 'level_id' => (string) $r['level_id'],
        ], $rows);
    }

    /** @return array{0: string, 1: list<mixed>} */
    private function where(string $tenantId, string $establishmentId, Perimeter $perimeter, StatisticsFilter $filter): array
    {
        [$scope, $scopeParams] = $this->reader->scope($perimeter, ['school' => 'school_id', 'department' => 'department_id', 'program' => 'program_id', 'level' => 'level_id', 'group' => 'group_id']);
        $where = "tenant_id = ? AND establishment_id = ? AND status IN ('enrolled', 'financial_hold', 'suspended') AND " . $scope;
        $params = [$tenantId, $establishmentId, ...$scopeParams];
        if ($filter->programId !== null) {
            $where .= ' AND program_id = ?';
            $params[] = $filter->programId;
        }
        if ($filter->academicYearId !== null) {
            $where .= ' AND academic_year_id = ?';
            $params[] = $filter->academicYearId;
        }
        if ($filter->from !== null) {
            $where .= ' AND validated_at >= ?';
            $params[] = $filter->from;
        }
        if ($filter->to !== null) {
            $where .= ' AND validated_at < ?';
            $params[] = $filter->to . 'T99';
        }

        return [$where, $params];
    }
}
