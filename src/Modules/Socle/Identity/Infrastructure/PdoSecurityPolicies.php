<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Infrastructure;

use PDO;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Identity\Application\Moment;
use Scholaris\Modules\Socle\Identity\Contracts\SecurityPolicies;
use Scholaris\Modules\Socle\Identity\Domain\Role;

/** Règles de sécurité réglables. Voir le contrat. Journal dans le client technique `platform`. */
final class PdoSecurityPolicies implements SecurityPolicies
{
    private const SESSION_KEY = 'session_hours';

    public function __construct(
        private readonly ConnectionFactory $connections,
        private readonly AuditLog $audit,
        private readonly Moment $moment,
    ) {
    }

    public function roles(): array
    {
        $statement = $this->connections->pdo()->prepare('SELECT code, label_fr, label_en, level, is_sensitive FROM roles ORDER BY level DESC, code');
        $statement->execute();

        return array_map(static fn (array $r): array => [
            'code' => (string) $r['code'],
            'label_fr' => (string) $r['label_fr'],
            'label_en' => (string) $r['label_en'],
            'level' => (string) $r['level'],
            'two_factor_required' => in_array((string) $r['code'], Role::PLATFORM_ROLES, true) || (int) $r['is_sensitive'] === 1,
            'locked' => in_array((string) $r['code'], Role::PLATFORM_ROLES, true),
        ], array_values($statement->fetchAll(PDO::FETCH_ASSOC)));
    }

    public function setTwoFactorRequired(string $role, bool $required, string $actorId): void
    {
        if (in_array($role, Role::PLATFORM_ROLES, true)) {
            throw HttpException::conflict('La double authentification est toujours exigée pour les rôles de la plateforme.');
        }
        $statement = $this->connections->pdo()->prepare('SELECT is_sensitive FROM roles WHERE code = ?');
        $statement->execute([$role]);
        $before = $statement->fetchColumn();
        if ($before === false) {
            throw HttpException::notFound('Rôle inconnu.');
        }
        $this->connections->pdo()->prepare('UPDATE roles SET is_sensitive = ? WHERE code = ?')->execute([$required ? 1 : 0, $role]);
        $this->audit->append(Role::PLATFORM_TENANT, $actorId, 'identity.role_two_factor_changed', 'role', $role, ['required' => (int) $before === 1], ['required' => $required]);
    }

    public function sessionHours(): int
    {
        $statement = $this->connections->pdo()->prepare('SELECT policy_value FROM identity_security_policies WHERE policy_key = ?');
        $statement->execute([self::SESSION_KEY]);
        $raw = $statement->fetchColumn();
        $hours = is_numeric($raw) ? (int) $raw : self::SESSION_HOURS_DEFAULT;

        return $hours < self::SESSION_HOURS_MIN || $hours > self::SESSION_HOURS_MAX ? self::SESSION_HOURS_DEFAULT : $hours;
    }

    public function setSessionHours(int $hours, string $actorId): void
    {
        if ($hours < self::SESSION_HOURS_MIN || $hours > self::SESSION_HOURS_MAX) {
            throw HttpException::validation(['session_hours' => [sprintf('Valeur attendue entre %d et %d heures.', self::SESSION_HOURS_MIN, self::SESSION_HOURS_MAX)]]);
        }
        $before = $this->sessionHours();
        $now = $this->moment->iso();
        $pdo = $this->connections->pdo();
        $update = $pdo->prepare('UPDATE identity_security_policies SET policy_value = ?, updated_by = ?, updated_at = ? WHERE policy_key = ?');
        $update->execute([(string) $hours, $actorId, $now, self::SESSION_KEY]);
        if ($update->rowCount() === 0) {
            $pdo->prepare('INSERT INTO identity_security_policies (policy_key, policy_value, updated_by, updated_at) VALUES (?, ?, ?, ?)')->execute([self::SESSION_KEY, (string) $hours, $actorId, $now]);
        }
        $this->audit->append(Role::PLATFORM_TENANT, $actorId, 'identity.session_hours_changed', 'setting', self::SESSION_KEY, ['hours' => $before], ['hours' => $hours]);
    }
}
