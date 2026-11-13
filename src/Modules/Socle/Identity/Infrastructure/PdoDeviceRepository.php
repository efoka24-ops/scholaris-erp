<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Infrastructure;

use Scholaris\Core\Support\Ulid;

/** Appareils connus d'un utilisateur : empreinte, dernière activité, confiance de 30 jours, révocation. */
final class PdoDeviceRepository extends PdoRepository
{
    /** @return array{id: string, name: string, location: ?string, ip: ?string, last_seen_at: string, trusted_until: ?string}|null */
    public function findActiveByFingerprint(string $tenantId, string $userId, string $fingerprint): ?array
    {
        $row = $this->one(
            'SELECT * FROM devices WHERE tenant_id = ? AND user_id = ? AND fingerprint = ? AND revoked_at IS NULL',
            [$tenantId, $userId, $fingerprint],
        );

        return $row === null ? null : self::map($row);
    }

    /** @return array{id: string, name: string, location: ?string, ip: ?string, last_seen_at: string, trusted_until: ?string}|null */
    public function find(string $tenantId, string $userId, string $deviceId): ?array
    {
        $row = $this->one('SELECT * FROM devices WHERE tenant_id = ? AND user_id = ? AND id = ? AND revoked_at IS NULL', [$tenantId, $userId, $deviceId]);

        return $row === null ? null : self::map($row);
    }

    /** @return list<array{id: string, name: string, location: ?string, ip: ?string, last_seen_at: string, trusted_until: ?string}> */
    public function listActive(string $tenantId, string $userId): array
    {
        return array_map(
            self::map(...),
            $this->rows('SELECT * FROM devices WHERE tenant_id = ? AND user_id = ? AND revoked_at IS NULL ORDER BY last_seen_at DESC, id DESC', [$tenantId, $userId]),
        );
    }

    public function insert(string $tenantId, string $userId, string $fingerprint, string $name, ?string $location, ?string $ip, string $now): string
    {
        $id = (string) new Ulid();
        $this->run(
            'INSERT INTO devices (id, tenant_id, user_id, fingerprint, name, location, ip, last_seen_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$id, $tenantId, $userId, $fingerprint, $name, $location, $ip, $now, $now],
        );

        return $id;
    }

    public function touch(string $tenantId, string $deviceId, ?string $ip, string $now): void
    {
        $this->run('UPDATE devices SET last_seen_at = ?, ip = ? WHERE tenant_id = ? AND id = ?', [$now, $ip, $tenantId, $deviceId]);
    }

    public function trustUntil(string $tenantId, string $deviceId, ?string $until): void
    {
        $this->run('UPDATE devices SET trusted_until = ? WHERE tenant_id = ? AND id = ?', [$until, $tenantId, $deviceId]);
    }

    public function clearTrust(string $tenantId, string $userId): void
    {
        $this->run('UPDATE devices SET trusted_until = NULL WHERE tenant_id = ? AND user_id = ?', [$tenantId, $userId]);
    }

    public function revoke(string $tenantId, string $deviceId, string $now): void
    {
        $this->run('UPDATE devices SET revoked_at = ?, trusted_until = NULL WHERE tenant_id = ? AND id = ? AND revoked_at IS NULL', [$now, $tenantId, $deviceId]);
    }

    public function revokeAll(string $tenantId, string $userId, string $now): void
    {
        $this->run('UPDATE devices SET revoked_at = ?, trusted_until = NULL WHERE tenant_id = ? AND user_id = ? AND revoked_at IS NULL', [$now, $tenantId, $userId]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: string, name: string, location: ?string, ip: ?string, last_seen_at: string, trusted_until: ?string}
     */
    private static function map(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'name' => (string) $row['name'],
            'location' => self::nullableString($row['location']),
            'ip' => self::nullableString($row['ip']),
            'last_seen_at' => (string) $row['last_seen_at'],
            'trusted_until' => self::nullableString($row['trusted_until']),
        ];
    }
}
