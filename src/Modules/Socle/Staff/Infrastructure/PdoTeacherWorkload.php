<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Staff\Infrastructure;

use Scholaris\Modules\Socle\Staff\Contracts\TeacherWorkload;

/** Affectations et heures d'une personne, lues dans les tables du module. */
final class PdoTeacherWorkload implements TeacherWorkload
{
    public function __construct(private readonly PdoStore $store)
    {
    }

    public function assignmentsOf(string $tenantId, string $personId): array
    {
        $rows = $this->store->rows(
            'SELECT a.establishment_id, a.structure_id, a.subject_label, a.session_kind
             FROM staff_assignments a JOIN staff_members m ON m.id = a.staff_id AND m.tenant_id = a.tenant_id
             WHERE a.tenant_id = ? AND m.person_id = ? AND a.status = ? ORDER BY a.establishment_id, a.structure_id, a.id',
            [$tenantId, $personId, 'active'],
        );

        return array_map(static fn (array $row): array => [
            'establishment_id' => (string) $row['establishment_id'],
            'structure_id' => $row['structure_id'] === null ? null : (string) $row['structure_id'],
            'subject_label' => $row['subject_label'] === null ? null : (string) $row['subject_label'],
            'session_kind' => (string) $row['session_kind'],
        ], $rows);
    }

    public function hoursOf(string $tenantId, string $establishmentId, string $personId, string $month): array
    {
        $rows = $this->store->rows(
            'SELECT h.status AS hour_status, COALESCE(SUM(h.minutes), 0) AS total
             FROM vacation_hours h JOIN staff_members m ON m.id = h.staff_id AND m.tenant_id = h.tenant_id
             WHERE h.tenant_id = ? AND h.establishment_id = ? AND m.person_id = ? AND h.held_on >= ? AND h.held_on <= ?
             GROUP BY h.status',
            [$tenantId, $establishmentId, $personId, $month . '-01', $month . '-31'],
        );
        $sums = ['declared' => 0, 'validated' => 0, 'rejected' => 0];
        foreach ($rows as $row) {
            $status = (string) $row['hour_status'];
            if (isset($sums[$status])) {
                $sums[$status] = (int) $row['total'];
            }
        }

        return [
            'month' => $month,
            'declared_minutes' => $sums['declared'],
            'validated_minutes' => $sums['validated'],
            'rejected_minutes' => $sums['rejected'],
        ];
    }
}
