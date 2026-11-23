<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Infrastructure;

use PDO;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Identity\Application\Moment;
use Scholaris\Modules\Socle\Identity\Contracts\AccountActivity;
use Scholaris\Modules\Socle\Identity\Domain\Role;
use Scholaris\Modules\Socle\Identity\Domain\User;

/** Activité des comptes pour la plateforme. Voir le contrat. Valeurs toujours liées. */
final class PdoAccountActivity implements AccountActivity
{
    public function __construct(
        private readonly ConnectionFactory $connections,
        private readonly Moment $moment,
        private readonly AuditLog $audit,
    ) {
    }

    public function activeSessions(string $since, int $limit = 200): array
    {
        $rows = $this->rows(
            sprintf(
                'SELECT t.tenant_id, t.user_id, t.active_role, t.created_at, COALESCE(t.last_used_at, t.created_at) AS seen, p.first_name, p.last_name, d.name AS device, u.person_id'
                . ' FROM access_tokens t JOIN users u ON u.tenant_id = t.tenant_id AND u.id = t.user_id'
                . ' JOIN people p ON p.tenant_id = u.tenant_id AND p.id = u.person_id'
                . ' LEFT JOIN devices d ON d.tenant_id = t.tenant_id AND d.id = t.device_id'
                . ' WHERE t.revoked_at IS NULL AND t.expires_at > ? AND COALESCE(t.last_used_at, t.created_at) >= ? ORDER BY seen DESC, t.id LIMIT %d',
                max(1, min($limit, 1000)),
            ),
            [$this->moment->iso(), $since],
        );
        $roles = $this->rolesOf(array_map(static fn (array $r): array => [(string) $r['tenant_id'], (string) $r['person_id']], $rows));

        return array_map(static fn (array $r): array => [
            'tenant_id' => (string) $r['tenant_id'],
            'user_id' => (string) $r['user_id'],
            'first_name' => (string) $r['first_name'],
            'last_name' => (string) $r['last_name'],
            'role' => $r['active_role'] !== null ? (string) $r['active_role'] : ($roles[$r['tenant_id'] . '|' . $r['person_id']][0] ?? null),
            'since' => (string) $r['created_at'],
            'last_seen_at' => (string) $r['seen'],
            'device' => $r['device'] === null ? null : (string) $r['device'],
        ], $rows);
    }

    public function activeUsersSince(string $since): int
    {
        return (int) $this->scalar('SELECT COUNT(*) FROM users WHERE tenant_id <> ? AND last_login_at >= ?', [Role::PLATFORM_TENANT, $since]);
    }

    public function lastLoginByTenant(): array
    {
        $out = [];
        foreach ($this->rows('SELECT tenant_id, MAX(last_login_at) AS last_login FROM users WHERE tenant_id <> ? GROUP BY tenant_id', [Role::PLATFORM_TENANT]) as $row) {
            $out[(string) $row['tenant_id']] = $row['last_login'] === null ? null : (string) $row['last_login'];
        }

        return $out;
    }

    public function failedLoginsSince(string $since): int
    {
        return (int) $this->scalar('SELECT COUNT(*) FROM login_attempts WHERE failed_count > 0 AND updated_at >= ?', [$since]);
    }

    public function lockedAccounts(): int
    {
        return (int) $this->scalar('SELECT COUNT(*) FROM users WHERE tenant_id <> ? AND status = ?', [Role::PLATFORM_TENANT, User::LOCKED]);
    }

    public function account(string $tenantId, string $userId): array
    {
        $rows = $this->rows(
            'SELECT u.id, u.person_id, u.tenant_id, p.first_name, p.last_name, u.phone, u.email, u.locale, u.status, u.two_factor_confirmed_at, u.last_login_at, u.created_at'
            . ' FROM users u JOIN people p ON p.tenant_id = u.tenant_id AND p.id = u.person_id WHERE u.tenant_id = ? AND u.id = ?',
            [$tenantId, $userId],
        );
        $row = $rows[0] ?? throw HttpException::notFound('Utilisateur introuvable dans ce client.');
        $sessions = $this->rows(
            'SELECT t.id, d.name AS device, t.created_at, t.last_used_at, t.expires_at FROM access_tokens t LEFT JOIN devices d ON d.tenant_id = t.tenant_id AND d.id = t.device_id'
            . ' WHERE t.tenant_id = ? AND t.user_id = ? AND t.revoked_at IS NULL AND t.expires_at > ? ORDER BY t.created_at DESC',
            [$tenantId, $userId, $this->moment->iso()],
        );

        return [
            'user_id' => (string) $row['id'],
            'person_id' => (string) $row['person_id'],
            'tenant_id' => (string) $row['tenant_id'],
            'first_name' => (string) $row['first_name'],
            'last_name' => (string) $row['last_name'],
            'phone' => $row['phone'] === null ? null : (string) $row['phone'],
            'email' => $row['email'] === null ? null : (string) $row['email'],
            'locale' => (string) $row['locale'],
            'status' => (string) $row['status'],
            'roles' => $this->rolesOf([[$tenantId, (string) $row['person_id']]])[$tenantId . '|' . $row['person_id']] ?? [],
            'two_factor_enabled' => $row['two_factor_confirmed_at'] !== null,
            'last_login_at' => $row['last_login_at'] === null ? null : (string) $row['last_login_at'],
            'created_at' => (string) $row['created_at'],
            'sessions' => array_map(static fn (array $s): array => [
                'id' => (string) $s['id'],
                'device' => $s['device'] === null ? null : (string) $s['device'],
                'created_at' => (string) $s['created_at'],
                'last_used_at' => $s['last_used_at'] === null ? null : (string) $s['last_used_at'],
                'expires_at' => (string) $s['expires_at'],
            ], $sessions),
        ];
    }

    public function revokeSessions(string $tenantId, string $userId, string $actorId): int
    {
        $this->account($tenantId, $userId);
        $now = $this->moment->iso();
        $statement = $this->connections->pdo()->prepare('UPDATE access_tokens SET revoked_at = ? WHERE tenant_id = ? AND user_id = ? AND revoked_at IS NULL');
        $statement->execute([$now, $tenantId, $userId]);
        $count = $statement->rowCount();
        $this->audit->append($tenantId, $actorId, 'identity.sessions_revoked', 'user', $userId, null, ['by' => 'platform', 'sessions' => $count]);

        return $count;
    }

    /**
     * @param list<array{0: string, 1: string}> $people (client, personne)
     * @return array<string, list<string>> « client|personne » => rôles en vigueur
     */
    private function rolesOf(array $people): array
    {
        $out = [];
        $now = $this->moment->iso();
        foreach (array_unique(array_map(static fn (array $p): string => $p[0], $people)) as $tenantId) {
            foreach ($this->rows('SELECT DISTINCT person_id, role FROM role_assignments WHERE tenant_id = ? AND starts_at <= ? AND (ends_at IS NULL OR ends_at > ?) ORDER BY role', [$tenantId, $now, $now]) as $row) {
                $out[$tenantId . '|' . $row['person_id']][] = (string) $row['role'];
            }
        }

        return $out;
    }

    /**
     * @param list<scalar|null> $params
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, array $params): array
    {
        $statement = $this->connections->pdo()->prepare($sql);
        $statement->execute($params);

        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param list<scalar|null> $params */
    private function scalar(string $sql, array $params): mixed
    {
        $statement = $this->connections->pdo()->prepare($sql);
        $statement->execute($params);

        return $statement->fetchColumn();
    }
}
