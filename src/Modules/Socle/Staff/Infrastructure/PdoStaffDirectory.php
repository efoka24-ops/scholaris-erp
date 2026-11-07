<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Staff\Infrastructure;

use Scholaris\Modules\Socle\Staff\Contracts\StaffDirectory;
use Scholaris\Modules\Socle\Staff\Contracts\StaffInfo;

final class PdoStaffDirectory implements StaffDirectory
{
    public function __construct(private readonly PdoStore $store)
    {
    }

    public function find(string $tenantId, string $personId): ?StaffInfo
    {
        $row = $this->store->where('staff_members', $tenantId, ['person_id' => $personId], 'id', 1)[0] ?? null;
        if ($row === null) {
            return null;
        }

        return new StaffInfo(
            (string) $row['person_id'],
            (string) $row['tenant_id'],
            (string) $row['kind'],
            $row['grade'] === null ? null : (string) $row['grade'],
            (string) $row['status'],
            (string) $row['id'],
        );
    }

    public function teacherIdsIn(string $tenantId, string $establishmentId): array
    {
        $rows = $this->store->rows(
            'SELECT DISTINCT m.person_id FROM staff_members m
             WHERE m.tenant_id = ? AND m.status = ? AND (m.home_establishment_id = ? OR EXISTS (
                SELECT 1 FROM staff_assignments a WHERE a.tenant_id = m.tenant_id AND a.staff_id = m.id AND a.establishment_id = ? AND a.status = ?
             )) ORDER BY m.person_id',
            [$tenantId, 'active', $establishmentId, $establishmentId, 'active'],
        );

        return array_map(static fn (array $row): string => (string) $row['person_id'], $rows);
    }
}
