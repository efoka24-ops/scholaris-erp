<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Staff\Infrastructure;

use Scholaris\Modules\Socle\Staff\Contracts\EstablishmentStaffRoster;
use Scholaris\Modules\Socle\Staff\Contracts\StaffMemberInfo;

/** Personnel actif d'un établissement : fiche rattachée à l'établissement, ou affectation active dans l'établissement. */
final class PdoEstablishmentStaffRoster implements EstablishmentStaffRoster
{
    public function __construct(private readonly PdoStore $store)
    {
    }

    public function membersOf(string $tenantId, string $establishmentId): array
    {
        $rows = $this->store->rows(
            'SELECT m.id, m.person_id, m.tenant_id, m.kind, m.status, m.grade, m.category,
                (SELECT COUNT(*) FROM staff_assignments t WHERE t.tenant_id = m.tenant_id AND t.staff_id = m.id AND t.establishment_id = ?
                    AND t.status = ? AND (t.subject_id IS NOT NULL OR t.subject_label IS NOT NULL OR t.structure_id IS NOT NULL)) AS teaching
             FROM staff_members m
             WHERE m.tenant_id = ? AND m.status = ? AND (m.home_establishment_id = ? OR EXISTS (
                SELECT 1 FROM staff_assignments a WHERE a.tenant_id = m.tenant_id AND a.staff_id = m.id AND a.establishment_id = ? AND a.status = ?
             )) ORDER BY m.person_id',
            [$establishmentId, 'active', $tenantId, 'active', $establishmentId, $establishmentId, 'active'],
        );

        return array_map(static fn (array $row): StaffMemberInfo => new StaffMemberInfo(
            (string) $row['id'],
            (string) $row['person_id'],
            (string) $row['tenant_id'],
            (string) $row['kind'],
            (string) $row['status'],
            $row['grade'] === null ? null : (string) $row['grade'],
            $row['category'] === null ? null : (string) $row['category'],
            (int) $row['teaching'] > 0,
        ), $rows);
    }
}
