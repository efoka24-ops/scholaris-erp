<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Infrastructure;

use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Socle\Identity\Application\Moment;
use Scholaris\Modules\Socle\Identity\Application\PermissionResolver;
use Scholaris\Modules\Socle\Identity\Contracts\PermissionScopes;
use Scholaris\Modules\Socle\Identity\Contracts\Scope;
use Scholaris\Modules\Socle\Identity\Domain\RoleAssignment;
use Scholaris\Modules\Socle\Identity\Domain\ScopeType;
use Scholaris\Modules\Socle\Organisation\Contracts\TerritoryDirectory;

/** Périmètres déduits des affectations de rôle en vigueur (mêmes règles que RoleBasedAccess::can, établissement par établissement). */
final class RoleBasedPermissionScopes implements PermissionScopes
{
    public function __construct(
        private readonly PdoUserRepository $users,
        private readonly PdoServiceAccountRepository $serviceAccounts,
        private readonly PermissionResolver $resolver,
        private readonly PdoRoleCatalog $roles,
        private readonly LazyEstablishmentDirectory $directory,
        private readonly TerritoryDirectory $territories,
        private readonly Moment $moment,
    ) {
    }

    public function scopesFor(Principal $principal, string $permission, string $establishmentId): array
    {
        if ($this->directory->find($principal->tenantId, $establishmentId) === null) {
            return [];
        }
        if (str_starts_with($principal->userId, 'svc:')) {
            $granted = in_array($permission, $this->serviceAccounts->scopesOf($principal->tenantId, substr($principal->userId, 4)), true);

            return $granted ? [new Scope(Scope::ESTABLISHMENT, $establishmentId, 'service_account', $principal->tenantId, $establishmentId)] : [];
        }
        $user = $this->users->find($principal->tenantId, $principal->userId);
        if ($user === null || !$user->isActive()) {
            return [];
        }

        $granting = [];
        $scopes = [];
        foreach ($this->resolver->activeAssignments($user->tenantId, $user->personId) as $assignment) {
            if (!$this->appliesTo($principal->tenantId, $assignment, $establishmentId)) {
                continue;
            }
            $granting[$assignment->role] ??= in_array($permission, $this->roles->permissionsOf([$assignment->role]), true);
            if (!$granting[$assignment->role]) {
                continue;
            }
            if ($assignment->scopeType === ScopeType::Territory) {
                $territoryId = $assignment->scopeId;
                if ($territoryId === null || $territoryId === '') {
                    continue;
                }
                $scope = Scope::territory($territoryId, $assignment->role, $principal->tenantId, $establishmentId);
            } else {
                $scope = match ($assignment->scopeType) {
                    ScopeType::Tenant, ScopeType::Establishment => new Scope(Scope::ESTABLISHMENT, $establishmentId, $assignment->role, $principal->tenantId, $establishmentId),
                    ScopeType::OrgUnit => new Scope(Scope::ORG_UNIT, (string) $assignment->scopeId, $assignment->role, $principal->tenantId, $establishmentId),
                    ScopeType::Structure => new Scope(Scope::STRUCTURE, (string) $assignment->scopeId, $assignment->role, $principal->tenantId, $establishmentId),
                    ScopeType::ClassGroup => new Scope(Scope::CLASS_GROUP, (string) $assignment->scopeId, $assignment->role, $principal->tenantId, $establishmentId),
                };
            }
            $scopes[$scope->type . ':' . $scope->id] ??= $scope;
        }

        return array_values($scopes);
    }

    private function appliesTo(string $tenantId, RoleAssignment $assignment, string $establishmentId): bool
    {
        if ($assignment->isTenantWide()) {
            return true;
        }
        if ($assignment->scopeType === ScopeType::Territory) {
            $territoryId = $assignment->scopeId;
            if ($territoryId === null || $territoryId === '') {
                return false;
            }

            return in_array($establishmentId, $this->territories->establishmentsIn($tenantId, $territoryId, $this->moment->iso()), true);
        }

        return $assignment->appliesTo($establishmentId);
    }
}
