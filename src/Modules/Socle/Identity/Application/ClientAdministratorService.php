<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Identity\Contracts\AccountProvisioner;
use Scholaris\Modules\Socle\Identity\Contracts\ClientAdministrators;
use Scholaris\Modules\Socle\Identity\Domain\Role;
use Scholaris\Modules\Socle\Identity\Domain\RoleAssignment;
use Scholaris\Modules\Socle\Identity\Domain\ScopeType;
use Scholaris\Modules\Socle\Identity\Infrastructure\PdoRoleAssignmentRepository;
use Scholaris\Modules\Socle\Identity\Infrastructure\PdoUserRepository;

/** Premier administrateur d'un client : compte en attente d'activation par code + rôle `super_admin` de niveau client. */
final class ClientAdministratorService implements ClientAdministrators
{
    public function __construct(
        private readonly AccountProvisioner $provisioner,
        private readonly PdoUserRepository $users,
        private readonly PdoRoleAssignmentRepository $assignments,
        private readonly AuditLog $audit,
        private readonly Moment $moment,
    ) {
    }

    public function invite(string $tenantId, string $firstName, string $lastName, ?string $phone, ?string $email, string $locale, string $grantedBy): array
    {
        if ($tenantId === '' || $tenantId === Role::PLATFORM_TENANT) {
            throw HttpException::validation(['tenant_id' => ['Client invalide.']]);
        }
        $ids = $this->provisioner->provision($tenantId, $firstName, $lastName, $phone, $email, $locale);
        $user = $this->users->find($tenantId, $ids['user_id']);
        $now = $this->moment->iso();
        $this->assignments->insert(new RoleAssignment(
            (string) new Ulid(),
            $tenantId,
            $ids['person_id'],
            Role::SUPER_ADMIN,
            ScopeType::Tenant,
            $tenantId,
            null,
            $now,
            null,
            null,
            $grantedBy,
            $now,
        ));
        $this->audit->append($tenantId, null, 'identity.role_assigned', 'role_assignment', $ids['person_id'], null, ['role' => Role::SUPER_ADMIN, 'person_id' => $ids['person_id'], 'granted_by' => $grantedBy]);

        return $ids + ['login' => (string) ($user->phone ?? $user->email ?? '')];
    }
}
