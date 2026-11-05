<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Application;

use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Identity\Contracts\AccountAdministration;
use Scholaris\Modules\Socle\Identity\Contracts\PlatformStaff;
use Scholaris\Modules\Socle\Identity\Domain\Role;
use Scholaris\Modules\Socle\Identity\Domain\RoleAssignment;
use Scholaris\Modules\Socle\Identity\Domain\ScopeType;
use Scholaris\Modules\Socle\Identity\Infrastructure\PdoRoleAssignmentRepository;
use Scholaris\Modules\Socle\Identity\Infrastructure\PdoUserRepository;

/** Membres de la plateforme et leur rôle. Voir le contrat. */
final class PlatformStaffService implements PlatformStaff
{
    public function __construct(
        private readonly AccountAdministration $accounts,
        private readonly ConnectionFactory $connections,
        private readonly PdoRoleAssignmentRepository $assignments,
        private readonly PdoUserRepository $users,
        private readonly AuditLog $audit,
        private readonly Moment $moment,
    ) {
    }

    public function members(): array
    {
        $members = [];
        foreach (Role::PLATFORM_ROLES as $role) {
            foreach ($this->accounts->holders(Role::PLATFORM_TENANT, $role) as $holder) {
                // Un membre n'a qu'un rôle ; si deux affectations coexistent (reprise ancienne), le premier rôle de la liste l'emporte.
                $members[$holder['user_id']] ??= $holder + ['role' => $role];
            }
        }
        $list = array_values($members);
        usort($list, static fn (array $a, array $b): int => [$a['last_name'], $a['first_name'], $a['user_id']] <=> [$b['last_name'], $b['first_name'], $b['user_id']]);

        return $list;
    }

    public function roleOf(string $userId): ?string
    {
        foreach ($this->members() as $member) {
            if ($member['user_id'] === $userId) {
                return $member['role'];
            }
        }

        return null;
    }

    public function assign(string $userId, string $role, string $actorId): void
    {
        if (!in_array($role, Role::PLATFORM_ROLES, true)) {
            throw HttpException::validation(['role' => ['Rôle de plateforme inconnu.']]);
        }
        $before = $this->roleOf($userId);
        $user = $this->users->find(Role::PLATFORM_TENANT, $userId);
        if ($before === null || $user === null) {
            throw HttpException::notFound('Membre de la plateforme introuvable.');
        }
        if ($before === $role) {
            return;
        }
        $now = $this->moment->iso();
        $placeholders = implode(', ', array_fill(0, count(Role::PLATFORM_ROLES), '?'));
        $this->connections->pdo()->prepare(
            'UPDATE role_assignments SET ends_at = ? WHERE tenant_id = ? AND person_id = ? AND role IN (' . $placeholders . ') AND (ends_at IS NULL OR ends_at > ?)',
        )->execute([$now, Role::PLATFORM_TENANT, $user->personId, ...Role::PLATFORM_ROLES, $now]);
        $this->assignments->insert(new RoleAssignment((string) new Ulid(), Role::PLATFORM_TENANT, $user->personId, $role, ScopeType::Tenant, Role::PLATFORM_TENANT, null, $now, null, null, $actorId, $now));
        $this->audit->append(Role::PLATFORM_TENANT, $actorId, 'identity.platform_role_changed', 'user', $userId, ['role' => $before], ['role' => $role]);
    }
}
