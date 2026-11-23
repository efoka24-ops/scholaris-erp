<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Infrastructure;

use Scholaris\Core\Support\Ulid;

/** Comptes de service (intégrations) : clé secrète hachée, rotation avec période de grâce pour l'ancienne clé. */
final class PdoServiceAccountRepository extends PdoRepository
{
    /** @param list<string> $scopes permissions accordées */
    public function insert(string $tenantId, string $name, array $scopes, string $prefix, string $keyHash, string $now): string
    {
        $id = (string) new Ulid();
        $this->run(
            'INSERT INTO service_accounts (id, tenant_id, name, scopes, key_prefix, key_hash, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$id, $tenantId, $name, json_encode($scopes, JSON_THROW_ON_ERROR), $prefix, $keyHash, $now],
        );

        return $id;
    }

    /**
     * Compte dont la clé courante, ou l'ancienne clé encore en période de grâce, correspond à l'empreinte.
     * Recherche par préfixe puis comparaison en temps constant.
     *
     * @return array{id: string, tenant_id: string, scopes: list<string>}|null
     */
    public function findByKey(string $prefix, string $keyHash, string $now): ?array
    {
        foreach ($this->rows('SELECT * FROM service_accounts WHERE key_prefix = ? AND revoked_at IS NULL', [$prefix]) as $row) {
            $current = hash_equals((string) $row['key_hash'], $keyHash);
            $previous = $row['previous_key_hash'] !== null
                && hash_equals((string) $row['previous_key_hash'], $keyHash)
                && $row['previous_valid_until'] !== null
                && (string) $row['previous_valid_until'] > $now;
            if ($current || $previous) {
                /** @var list<string> $scopes */
                $scopes = (array) json_decode((string) $row['scopes'], true);

                return ['id' => (string) $row['id'], 'tenant_id' => (string) $row['tenant_id'], 'scopes' => $scopes];
            }
        }

        return null;
    }

    /** @return list<string> permissions accordées au compte (vide s'il est révoqué ou inconnu) */
    public function scopesOf(string $tenantId, string $id): array
    {
        $row = $this->one('SELECT scopes FROM service_accounts WHERE tenant_id = ? AND id = ? AND revoked_at IS NULL', [$tenantId, $id]);
        if ($row === null) {
            return [];
        }

        return array_values(array_map('strval', (array) json_decode((string) $row['scopes'], true)));
    }

    /** Remplace la clé secrète (le préfixe public reste) ; l'ancienne clé reste valable jusqu'à $graceUntil. */
    public function rotate(string $tenantId, string $id, string $newHash, string $graceUntil, string $now): bool
    {
        return $this->run(
            'UPDATE service_accounts SET previous_key_hash = key_hash, previous_valid_until = ?, key_hash = ?, rotated_at = ?
             WHERE tenant_id = ? AND id = ? AND revoked_at IS NULL',
            [$graceUntil, $newHash, $now, $tenantId, $id],
        ) === 1;
    }

    /** @return string|null préfixe public du compte */
    public function prefixOf(string $tenantId, string $id): ?string
    {
        $row = $this->one('SELECT key_prefix FROM service_accounts WHERE tenant_id = ? AND id = ? AND revoked_at IS NULL', [$tenantId, $id]);

        return $row === null ? null : (string) $row['key_prefix'];
    }

    public function revoke(string $tenantId, string $id, string $now): bool
    {
        return $this->run('UPDATE service_accounts SET revoked_at = ? WHERE tenant_id = ? AND id = ? AND revoked_at IS NULL', [$now, $tenantId, $id]) === 1;
    }
}
