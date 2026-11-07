<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Sync\Infrastructure;

/** Table `sync_devices`. Toute lecture ou écriture est bornée au client ET à l'établissement. */
final class DeviceRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @param array<string, scalar|null> $row */
    public function insert(array $row): void
    {
        $this->db->insert('sync_devices', $row);
    }

    /** @return array<string, mixed>|null */
    public function find(string $tenantId, string $establishmentId, string $id): ?array
    {
        return $this->db->one(
            'SELECT * FROM sync_devices WHERE tenant_id = ? AND establishment_id = ? AND id = ?',
            [$tenantId, $establishmentId, $id],
        );
    }

    /** @return list<array<string, mixed>> */
    public function page(string $tenantId, string $establishmentId, ?string $afterId, int $limit): array
    {
        return $this->db->all(
            'SELECT * FROM sync_devices WHERE tenant_id = ? AND establishment_id = ? AND id > ? ORDER BY id LIMIT ' . max(1, $limit),
            [$tenantId, $establishmentId, $afterId ?? ''],
        );
    }

    /** Révoque l'appareil s'il ne l'est pas déjà ; rend le nombre de lignes modifiées (0 = déjà révoqué ou absent). */
    public function revoke(string $tenantId, string $establishmentId, string $id, string $at, string $by): int
    {
        return $this->db->exec(
            'UPDATE sync_devices SET revoked_at = ?, revoked_by = ? WHERE tenant_id = ? AND establishment_id = ? AND id = ? AND revoked_at IS NULL',
            [$at, $by, $tenantId, $establishmentId, $id],
        );
    }

    public function touch(string $tenantId, string $establishmentId, string $id, string $at): void
    {
        $this->db->exec(
            'UPDATE sync_devices SET last_seen_at = ? WHERE tenant_id = ? AND establishment_id = ? AND id = ?',
            [$at, $tenantId, $establishmentId, $id],
        );
    }
}
