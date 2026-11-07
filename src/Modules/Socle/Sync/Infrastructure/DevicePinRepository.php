<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Sync\Infrastructure;

use Scholaris\Core\Support\Ulid;

/** PP7 : codes personnels hachés (`sync_device_pins`) et compteur d'échecs de l'appareil, bornés au client et à l'établissement. */
final class DevicePinRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    public function hashFor(string $tenantId, string $deviceId, string $userId): ?string
    {
        $row = $this->db->one('SELECT pin_hash FROM sync_device_pins WHERE tenant_id = ? AND device_id = ? AND user_id = ?', [$tenantId, $deviceId, $userId]);

        return $row === null ? null : (string) $row['pin_hash'];
    }

    public function save(string $tenantId, string $establishmentId, string $deviceId, string $userId, string $hash, string $at): void
    {
        $updated = $this->db->exec(
            'UPDATE sync_device_pins SET pin_hash = ?, updated_at = ? WHERE tenant_id = ? AND device_id = ? AND user_id = ?',
            [$hash, $at, $tenantId, $deviceId, $userId],
        );
        if ($updated === 0) {
            $this->db->insert('sync_device_pins', [
                'id' => (string) new Ulid(), 'tenant_id' => $tenantId, 'establishment_id' => $establishmentId, 'device_id' => $deviceId,
                'user_id' => $userId, 'pin_hash' => $hash, 'created_at' => $at, 'updated_at' => $at,
            ]);
        }
    }

    /** Ajoute un échec et rend le total ; verrouille l'appareil dès que $limit est atteint. */
    public function recordFailure(string $tenantId, string $establishmentId, string $deviceId, int $limit, string $at): int
    {
        $this->db->exec(
            'UPDATE sync_devices SET failed_unlocks = failed_unlocks + 1 WHERE tenant_id = ? AND establishment_id = ? AND id = ? AND locked_at IS NULL',
            [$tenantId, $establishmentId, $deviceId],
        );
        $this->db->exec(
            'UPDATE sync_devices SET locked_at = ? WHERE tenant_id = ? AND establishment_id = ? AND id = ? AND locked_at IS NULL AND failed_unlocks >= ?',
            [$at, $tenantId, $establishmentId, $deviceId, $limit],
        );
        $row = $this->db->one('SELECT failed_unlocks FROM sync_devices WHERE tenant_id = ? AND establishment_id = ? AND id = ?', [$tenantId, $establishmentId, $deviceId]);

        return $row === null ? $limit : (int) $row['failed_unlocks'];
    }

    public function resetFailures(string $tenantId, string $establishmentId, string $deviceId): void
    {
        $this->db->exec(
            'UPDATE sync_devices SET failed_unlocks = 0 WHERE tenant_id = ? AND establishment_id = ? AND id = ? AND locked_at IS NULL',
            [$tenantId, $establishmentId, $deviceId],
        );
    }
}
