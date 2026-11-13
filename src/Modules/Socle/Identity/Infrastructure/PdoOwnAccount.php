<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Infrastructure;

use PDO;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Identity\Application\CurrentSession;
use Scholaris\Modules\Socle\Identity\Application\Moment;
use Scholaris\Modules\Socle\Identity\Contracts\OwnAccount;

/** « Mon profil » du compte connecté. Voir le contrat. Valeurs toujours liées. */
final class PdoOwnAccount implements OwnAccount
{
    public function __construct(
        private readonly ConnectionFactory $connections,
        private readonly CurrentSession $session,
        private readonly PdoAccessTokenRepository $tokens,
        private readonly AuditLog $audit,
        private readonly Moment $moment,
    ) {
    }

    public function profile(string $tenantId, string $userId): array
    {
        $rows = $this->rows(
            'SELECT u.id, p.first_name, p.last_name, u.phone, u.email, u.locale, u.two_factor_confirmed_at, u.last_login_at'
            . ' FROM users u JOIN people p ON p.tenant_id = u.tenant_id AND p.id = u.person_id WHERE u.tenant_id = ? AND u.id = ?',
            [$tenantId, $userId],
        );
        $row = $rows[0] ?? throw HttpException::notFound();

        return [
            'user_id' => (string) $row['id'],
            'first_name' => (string) $row['first_name'],
            'last_name' => (string) $row['last_name'],
            'phone' => $row['phone'] === null ? null : (string) $row['phone'],
            'email' => $row['email'] === null ? null : (string) $row['email'],
            'locale' => (string) $row['locale'],
            'two_factor_enabled' => $row['two_factor_confirmed_at'] !== null,
            'last_login_at' => $row['last_login_at'] === null ? null : (string) $row['last_login_at'],
        ];
    }

    public function update(string $tenantId, string $userId, array $changes): array
    {
        $before = $this->profile($tenantId, $userId);
        $after = array_replace($before, array_intersect_key($changes, array_flip(['first_name', 'last_name', 'phone', 'email', 'locale'])));
        if ($after['phone'] === null && $after['email'] === null) {
            throw HttpException::validation(['email' => ['Gardez au moins un e-mail ou un téléphone : il sert d\'identifiant de connexion.']]);
        }
        foreach (['phone', 'email'] as $field) {
            if ($after[$field] !== null && $after[$field] !== $before[$field]
                && $this->rows('SELECT 1 FROM users WHERE tenant_id = ? AND ' . $field . ' = ? AND id <> ?', [$tenantId, $after[$field], $userId]) !== []) {
                throw HttpException::conflict($field === 'phone' ? 'Ce téléphone est déjà utilisé par un autre compte.' : 'Cet e-mail est déjà utilisé par un autre compte.');
            }
        }
        $now = $this->moment->iso();
        $pdo = $this->connections->pdo();
        $pdo->prepare('UPDATE users SET phone = ?, email = ?, locale = ?, updated_at = ? WHERE tenant_id = ? AND id = ?')
            ->execute([$after['phone'], $after['email'], $after['locale'], $now, $tenantId, $userId]);
        $pdo->prepare('UPDATE people SET first_name = ?, last_name = ?, phone = ?, email = ?, locale = ? WHERE tenant_id = ? AND id = (SELECT person_id FROM users WHERE tenant_id = ? AND id = ?)')
            ->execute([$after['first_name'], $after['last_name'], $after['phone'], $after['email'], $after['locale'], $tenantId, $tenantId, $userId]);
        $changed = array_keys(array_diff_assoc(array_map('strval', $after), array_map('strval', $before)));
        if ($changed !== []) {
            // Journal : les champs modifiés seulement, jamais les valeurs (téléphone, e-mail).
            $this->audit->append($tenantId, $userId, 'identity.profile_updated', 'user', $userId, null, ['fields' => $changed]);
        }

        return $this->profile($tenantId, $userId);
    }

    public function sessions(string $tenantId, string $userId): array
    {
        $current = $this->session->token()?->id;

        return array_map(static fn (array $s): array => [
            'id' => (string) $s['id'],
            'device' => $s['device'] === null ? null : (string) $s['device'],
            'created_at' => (string) $s['created_at'],
            'last_used_at' => $s['last_used_at'] === null ? null : (string) $s['last_used_at'],
            'expires_at' => (string) $s['expires_at'],
            'current' => $s['id'] === $current,
        ], $this->rows(
            'SELECT t.id, d.name AS device, t.created_at, t.last_used_at, t.expires_at FROM access_tokens t LEFT JOIN devices d ON d.tenant_id = t.tenant_id AND d.id = t.device_id'
            . ' WHERE t.tenant_id = ? AND t.user_id = ? AND t.revoked_at IS NULL AND t.expires_at > ? ORDER BY t.created_at DESC',
            [$tenantId, $userId, $this->moment->iso()],
        ));
    }

    public function revokeOtherSessions(string $tenantId, string $userId): int
    {
        $others = count(array_filter($this->sessions($tenantId, $userId), static fn (array $s): bool => !$s['current']));
        $this->tokens->revokeForUser($tenantId, $userId, $this->moment->iso(), $this->session->token()?->id);
        $this->audit->append($tenantId, $userId, 'identity.other_sessions_revoked', 'user', $userId, null, ['sessions' => $others]);

        return $others;
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
}
