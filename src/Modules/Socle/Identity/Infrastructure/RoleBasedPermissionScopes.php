<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Infrastructure;

use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Socle\Identity\Application\PermissionResolver;
use Scholaris\Modules\Socle\Identity\Contracts\PermissionScopes;
use Scholaris\Modules\Socle\Identity\Contracts\Scope;
use Scholaris\Modules\Socle\Identity\Domain\ScopeType;

/** Périmètres déduits des affectations de rôle en vigueur (mêmes règles que RoleBasedAccess::can, établissement par établissement). */
final class RoleBasedPermissionScopes implements PermissionScopes
{
    public function __construct(
        private readonly PdoUserRepository $users,
        private readonly PdoServiceAccountRepository $serviceAccounts,
        private readonly PermissionResolver $resolver,
        private readonly PdoRoleCatalog $roles,
        private readonly LazyEstablishmentDirectory $directory,
    ) {
    }

    public function scopesFor(Principal $principal, string $permission, string $establishmentId): array
    {
        if ($this->directory->find($principal->tenantId, $establishmentId) === null) {
            return [];
        }
        if (str_starts_with($principal->userId, 'svc:')) {
            $granted = in_array($permission, $this->serviceAccounts->scopesOf($principal->tenantId, substr($principal->userId, 4)), true);

            return $granted ? [new Scope(Scope::ESTABLISHMENT, $establishmentId, 'service_account')] : [];
        }
        $user = $this->users->find($principal->tenantId, $principal->userId);
        if ($user === null || !$user->isActive()) {
            return [];
        }

        $granting = [];
        $scopes = [];
        foreach ($this->resolver->activeAssignments($user->tenantId, $user->personId) as $assignment) {
            if (!$assignment->appliesTo($establishmentId)) {
                continue;
            }
            $granting[$assignment->role] ??= in_array($permission, $this->roles->permissionsOf([$assignment->role]), true);
            if (!$granting[$assignment->role]) {
                continue;
            }
            $scope = match ($assignment->scopeType) {
                ScopeType::Tenant, ScopeType::Establishment => new Scope(Scope::ESTABLISHMENT, $establishmentId, $assignment->role),
                ScopeType::OrgUnit => new Scope(Scope::ORG_UNIT, (string) $assignment->scopeId, $assignment->role),
                ScopeType::Structure => new Scope(Scope::STRUCTURE, (string) $assignment->scopeId, $assignment->role),
                ScopeType::ClassGroup => new Scope(Scope::CLASS_GROUP, (string) $assignment->scopeId, $assignment->role),
            };
            $scopes[$scope->type . ':' . $scope->id] ??= $scope;
        }

        return array_values($scopes);
    }
}
