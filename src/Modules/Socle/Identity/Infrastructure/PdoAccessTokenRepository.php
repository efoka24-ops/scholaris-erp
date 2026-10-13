<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Infrastructure;

use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Identity\Domain\AccessToken;

/** Jetons d'accès : seule l'empreinte SHA-256 du jeton est conservée, jamais le jeton lui-même. */
final class PdoAccessTokenRepository extends PdoRepository
{
    public function insert(
        string $tenantId,
        string $userId,
        string $deviceId,
        string $tokenHash,
        bool $remember,
        string $expiresAt,
        bool $needsTwoFactor,
        string $now,
    ): string {
        $id = (string) new Ulid();
        $this->run(
            'INSERT INTO access_tokens (id, tenant_id, user_id, device_id, token_hash, remember, expires_at, needs_two_factor, two_factor_attempts, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?)',
            [$id, $tenantId, $userId, $deviceId, $tokenHash, $remember ? 1 : 0, $expiresAt, $needsTwoFactor ? 1 : 0, $now],
        );

        return $id;
    }

    /** Recherche par empreinte (le jeton est le secret ; aucun client connu à ce stade). */
    public function findByHash(string $tokenHash): ?AccessToken
    {
        $row = $this->one('SELECT * FROM access_tokens WHERE token_hash = ?', [$tokenHash]);

        return $row === null ? null : self::map($row);
    }

    public function find(string $tenantId, string $id): ?AccessToken
    {
        $row = $this->one('SELECT * FROM access_tokens WHERE tenant_id = ? AND id = ?', [$tenantId, $id]);

        return $row === null ? null : self::map($row);
    }

    public function touch(string $tenantId, string $id, string $now): void
    {
        $this->run('UPDATE access_tokens SET last_used_at = ? WHERE tenant_id = ? AND id = ?', [$now, $tenantId, $id]);
    }

    public function revoke(string $tenantId, string $id, string $now): void
    {
        $this->run('UPDATE access_tokens SET revoked_at = ? WHERE tenant_id = ? AND id = ? AND revoked_at IS NULL', [$now, $tenantId, $id]);
    }

    public function revokeForUser(string $tenantId, string $userId, string $now, ?string $exceptId = null): void
    {
        if ($exceptId === null) {
            $this->run('UPDATE access_tokens SET revoked_at = ? WHERE tenant_id = ? AND user_id = ? AND revoked_at IS NULL', [$now, $tenantId, $userId]);

            return;
        }
        $this->run(
            'UPDATE access_tokens SET revoked_at = ? WHERE tenant_id = ? AND user_id = ? AND id <> ? AND revoked_at IS NULL',
            [$now, $tenantId, $userId, $exceptId],
        );
    }

    public function revokeForDevice(string $tenantId, string $deviceId, string $now): void
    {
        $this->run('UPDATE access_tokens SET revoked_at = ? WHERE tenant_id = ? AND device_id = ? AND revoked_at IS NULL', [$now, $tenantId, $deviceId]);
    }

    /** Impose une nouvelle double authentification aux jetons qui n'en ont jamais passé (rôle sensible attribué après la connexion). */
    public function revokeWithoutTwoFactor(string $tenantId, string $userId, string $now): void
    {
        $this->run(
            'UPDATE access_tokens SET revoked_at = ? WHERE tenant_id = ? AND user_id = ? AND needs_two_factor = 0 AND revoked_at IS NULL',
            [$now, $tenantId, $userId],
        );
    }

    public function markTwoFactorVerified(string $tenantId, string $id, string $now): void
    {
        $this->run(
            'UPDATE access_tokens SET two_factor_verified_at = ?, two_factor_attempts = 0 WHERE tenant_id = ? AND id = ?',
            [$now, $tenantId, $id],
        );
    }

    /** @return int nombre d'échecs de double authentification sur ce jeton, après incrément */
    public function incrementTwoFactorAttempts(string $tenantId, string $id): int
    {
        $this->run('UPDATE access_tokens SET two_factor_attempts = two_factor_attempts + 1 WHERE tenant_id = ? AND id = ?', [$tenantId, $id]);
        $row = $this->one('SELECT two_factor_attempts FROM access_tokens WHERE tenant_id = ? AND id = ?', [$tenantId, $id]);

        return $row === null ? 0 : (int) $row['two_factor_attempts'];
    }

    public function setContext(string $tenantId, string $id, ?string $establishmentId, ?string $role): void
    {
        $this->run(
            'UPDATE access_tokens SET active_establishment_id = ?, active_role = ? WHERE tenant_id = ? AND id = ?',
            [$establishmentId, $role, $tenantId, $id],
        );
    }

    /** @param array<string, mixed> $row */
    private static function map(array $row): AccessToken
    {
        return new AccessToken(
            (string) $row['id'],
            (string) $row['tenant_id'],
            (string) $row['user_id'],
            (string) $row['device_id'],
            (int) $row['remember'] === 1,
            (string) $row['expires_at'],
            (int) $row['needs_two_factor'] === 1,
            self::nullableString($row['two_factor_verified_at']),
            (int) $row['two_factor_attempts'],
            self::nullableString($row['active_establishment_id']),
            self::nullableString($row['active_role']),
            self::nullableString($row['last_used_at']),
            self::nullableString($row['revoked_at']),
        );
    }
}
