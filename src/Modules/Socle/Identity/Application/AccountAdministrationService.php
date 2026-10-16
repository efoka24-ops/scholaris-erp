<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Application;

use PDO;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Identity\Contracts\AccountAdministration;
use Scholaris\Modules\Socle\Identity\Contracts\AccountProvisioner;
use Scholaris\Modules\Socle\Identity\Domain\Role;
use Scholaris\Modules\Socle\Identity\Domain\RoleAssignment;
use Scholaris\Modules\Socle\Identity\Domain\ScopeType;
use Scholaris\Modules\Socle\Identity\Domain\User;
use Scholaris\Modules\Socle\Identity\Infrastructure\PdoAccessTokenRepository;
use Scholaris\Modules\Socle\Identity\Infrastructure\PdoDeviceRepository;
use Scholaris\Modules\Socle\Identity\Infrastructure\PdoRoleAssignmentRepository;

/** Comptes d'administration gérés par la plateforme (super_admin d'un client, platform_admin). Voir le contrat. */
final class AccountAdministrationService implements AccountAdministration
{
    private const ROLES = [Role::SUPER_ADMIN, Role::PLATFORM_ADMIN];

    public function __construct(
        private readonly ConnectionFactory $connections,
        private readonly AccountProvisioner $provisioner,
        private readonly PdoRoleAssignmentRepository $assignments,
        private readonly PdoAccessTokenRepository $tokens,
        private readonly PdoDeviceRepository $devices,
        private readonly AuditLog $audit,
        private readonly Moment $moment,
    ) {
    }

    public function holders(string $tenantId, string $role): array
    {
        $this->assertRole($tenantId, $role);
        $statement = $this->connections->pdo()->prepare(
            'SELECT DISTINCT u.id AS user_id, u.person_id, p.first_name, p.last_name, u.phone, u.email, u.locale, u.status,'
            . ' u.two_factor_confirmed_at, u.last_login_at, u.created_at'
            . ' FROM role_assignments r JOIN users u ON u.tenant_id = r.tenant_id AND u.person_id = r.person_id'
            . ' JOIN people p ON p.tenant_id = u.tenant_id AND p.id = u.person_id'
            . ' WHERE r.tenant_id = ? AND r.role = ? AND (r.ends_at IS NULL OR r.ends_at > ?) ORDER BY p.last_name, p.first_name, u.id',
        );
        $statement->execute([$tenantId, $role, $this->moment->iso()]);

        return array_map(static fn (array $row): array => [
            'user_id' => (string) $row['user_id'],
            'person_id' => (string) $row['person_id'],
            'first_name' => (string) $row['first_name'],
            'last_name' => (string) $row['last_name'],
            'phone' => $row['phone'] === null ? null : (string) $row['phone'],
            'email' => $row['email'] === null ? null : (string) $row['email'],
            'locale' => (string) $row['locale'],
            'status' => (string) $row['status'],
            'two_factor_enabled' => $row['two_factor_confirmed_at'] !== null,
            'last_login_at' => $row['last_login_at'] === null ? null : (string) $row['last_login_at'],
            'created_at' => (string) $row['created_at'],
        ], array_values($statement->fetchAll(PDO::FETCH_ASSOC)));
    }

    public function holder(string $tenantId, string $role, string $userId): array
    {
        foreach ($this->holders($tenantId, $role) as $holder) {
            if ($holder['user_id'] === $userId) {
                return $holder;
            }
        }

        throw HttpException::notFound('Administrateur introuvable.');
    }

    public function invitePlatformAdministrator(string $firstName, string $lastName, ?string $phone, ?string $email, string $locale, string $actorId): array
    {
        $tenant = Role::PLATFORM_TENANT;
        $ids = $this->provisioner->provision($tenant, $firstName, $lastName, $phone, $email, $locale);
        $now = $this->moment->iso();
        $this->assignments->insert(new RoleAssignment((string) new Ulid(), $tenant, $ids['person_id'], Role::PLATFORM_ADMIN, ScopeType::Tenant, $tenant, null, $now, null, null, $actorId, $now));
        $this->audit->append($tenant, $actorId, 'platform.admin_invited', 'user', $ids['user_id'], null, ['person_id' => $ids['person_id'], 'role' => Role::PLATFORM_ADMIN]);
        $holder = $this->holder($tenant, Role::PLATFORM_ADMIN, $ids['user_id']);

        return ['person_id' => $ids['person_id'], 'user_id' => $ids['user_id'], 'login' => (string) ($holder['email'] ?? $holder['phone'] ?? '')];
    }

    public function setBlocked(string $tenantId, string $role, string $userId, bool $blocked, string $actorId): void
    {
        $holder = $this->holder($tenantId, $role, $userId);
        $now = $this->moment->iso();
        if ($blocked) {
            $status = User::LOCKED;
        } else {
            $statement = $this->connections->pdo()->prepare('SELECT password_hash FROM users WHERE tenant_id = ? AND id = ?');
            $statement->execute([$tenantId, $userId]);
            $status = $statement->fetchColumn() === null ? User::PENDING : User::ACTIVE;
        }
        $this->connections->pdo()->prepare('UPDATE users SET status = ?, updated_at = ? WHERE tenant_id = ? AND id = ?')->execute([$status, $now, $tenantId, $userId]);
        if ($blocked) {
            $this->tokens->revokeForUser($tenantId, $userId, $now);
            $this->devices->revokeAll($tenantId, $userId, $now);
        }
        $this->audit->append($tenantId, $actorId, $blocked ? 'identity.account_blocked' : 'identity.account_unblocked', 'user', $userId, ['status' => $holder['status']], ['status' => $status, 'by' => 'platform']);
    }

    public function resetAccess(string $tenantId, string $role, string $userId, string $actorId): void
    {
        $holder = $this->holder($tenantId, $role, $userId);
        $now = $this->moment->iso();
        $this->connections->pdo()->prepare(
            'UPDATE users SET password_hash = NULL, two_factor_secret = NULL, two_factor_confirmed_at = NULL, status = ?, updated_at = ? WHERE tenant_id = ? AND id = ?',
        )->execute([User::PENDING, $now, $tenantId, $userId]);
        $this->tokens->revokeForUser($tenantId, $userId, $now);
        $this->devices->revokeAll($tenantId, $userId, $now);
        $this->audit->append($tenantId, $actorId, 'identity.access_reset', 'user', $userId, ['status' => $holder['status']], ['status' => User::PENDING, 'by' => 'platform']);
    }

    private function assertRole(string $tenantId, string $role): void
    {
        $platform = $tenantId === Role::PLATFORM_TENANT;
        if (!in_array($role, self::ROLES, true) || $platform !== ($role === Role::PLATFORM_ADMIN)) {
            throw HttpException::notFound();
        }
    }
}
