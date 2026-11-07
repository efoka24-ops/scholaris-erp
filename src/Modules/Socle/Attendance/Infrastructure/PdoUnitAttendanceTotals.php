<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Attendance\Infrastructure;

use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Modules\Socle\Attendance\Contracts\UnitAttendanceTotals;

final class PdoUnitAttendanceTotals implements UnitAttendanceTotals
{
    public function __construct(private readonly ConnectionFactory $connections)
    {
    }

    public function byStructureUnit(string $tenantId, string $establishmentId, ?string $from, ?string $to): array
    {
        $where = "s.tenant_id = ? AND s.establishment_id = ? AND s.status = 'validated' AND s.course_unit_id IS NOT NULL";
        $params = [$tenantId, $establishmentId];
        if ($from !== null) {
            $where .= ' AND s.session_date >= ?';
            $params[] = $from;
        }
        if ($to !== null) {
            $where .= ' AND s.session_date <= ?';
            $params[] = $to;
        }
        $statement = $this->connections->pdo()->prepare(
            'SELECT s.structure_id, s.course_unit_id, MAX(s.subject_label) AS label, COUNT(DISTINCT s.id) AS sessions, COUNT(r.id) AS records, '
            . "SUM(CASE WHEN r.status = 'P' THEN 1 ELSE 0 END) AS present, SUM(CASE WHEN r.status = 'R' THEN 1 ELSE 0 END) AS late, "
            . "SUM(CASE WHEN r.status = 'A' THEN 1 ELSE 0 END) AS absent, SUM(CASE WHEN r.status = 'A' AND r.justified = 0 THEN 1 ELSE 0 END) AS unjustified "
            . 'FROM attendance_sessions s LEFT JOIN attendance_records r ON r.tenant_id = s.tenant_id AND r.session_id = s.id WHERE ' . $where
            . ' GROUP BY s.structure_id, s.course_unit_id ORDER BY s.structure_id, label',
        );
        $statement->execute($params);

        return array_values(array_map(static fn (array $r): array => [
            'structure_id' => (string) $r['structure_id'], 'course_unit_id' => (string) $r['course_unit_id'], 'label' => (string) $r['label'],
            'sessions' => (int) $r['sessions'], 'records' => (int) $r['records'], 'present' => (int) $r['present'], 'late' => (int) $r['late'],
            'absent' => (int) $r['absent'], 'unjustified' => (int) $r['unjustified'],
        ], $statement->fetchAll(\PDO::FETCH_ASSOC)));
    }
}
