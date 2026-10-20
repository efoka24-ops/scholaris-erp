<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Infrastructure;

use Scholaris\Core\Security\EstablishmentAccess;
use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Socle\Identity\Application\Moment;
use Scholaris\Modules\Socle\Identity\Application\PermissionResolver;
use Scholaris\Modules\Socle\Identity\Domain\RoleAssignment;
use Scholaris\Modules\Socle\Identity\Domain\ScopeType;
use Scholaris\Modules\Socle\Organisation\Contracts\TerritoryDirectory;

/**
 * Implémente les interfaces du noyau à partir des affectations de rôle :
 *  - accès à un établissement : rôle de niveau client (tous les établissements du client) ou rôle rattaché à cet établissement ;
 *  - permission : déduite des rôles en vigueur dans le contexte. Comptes de service : leurs permissions déclarées.
 * Refus par défaut ; un établissement d'un autre client n'est jamais accessible.
 */
final class RoleBasedAccess implements EstablishmentAccess, PermissionChecker
{
    public function __construct(
        private readonly PdoUserRepository $users,
        private readonly PdoServiceAccountRepository $scopes,
        private readonly PermissionResolver $resolver,
        private readonly LazyEstablishmentDirectory $directory,
        private readonly TerritoryDirectory $territories,
        private readonly Moment $moment,
    ) {
    }

    public function canAccess(Principal $principal, string $establishmentId): bool
    {
        if ($this->directory->find($principal->tenantId, $establishmentId) === null) {
            return false;
        }
        if (str_starts_with($principal->userId, 'svc:')) {
            return true;
        }
        $user = $this->users->find($principal->tenantId, $principal->userId);
        if ($user === null || !$user->isActive()) {
            return false;
        }
        foreach ($this->resolver->activeAssignments($user->tenantId, $user->personId) as $assignment) {
            if ($this->appliesTo($user->tenantId, $assignment, $establishmentId)) {
                return true;
            }
        }

        return false;
    }

    public function can(Principal $principal, string $permission, ?string $establishmentId = null): bool
    {
        if (str_starts_with($principal->userId, 'svc:')) {
            return in_array($permission, $this->scopes->scopesOf($principal->tenantId, substr($principal->userId, 4)), true);
        }
        $user = $this->users->find($principal->tenantId, $principal->userId);
        if ($user === null || !$user->isActive()) {
            return false;
        }

        return $this->resolver->can($user->tenantId, $user->personId, $permission, $establishmentId);
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
