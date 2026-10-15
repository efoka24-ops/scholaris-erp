<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Application;

use Scholaris\Modules\Socle\Identity\Domain\Role;
use Scholaris\Modules\Socle\Identity\Domain\RoleAssignment;
use Scholaris\Modules\Socle\Identity\Infrastructure\PdoRoleAssignmentRepository;
use Scholaris\Modules\Socle\Identity\Infrastructure\PdoRoleCatalog;

/**
 * Déduit ce qu'une personne peut faire dans un contexte (client, établissement) à partir de ses affectations
 * de rôle en vigueur : dates de validité et délégations temporaires comprises. Refus par défaut.
 */
final class PermissionResolver
{
    public function __construct(
        private readonly PdoRoleAssignmentRepository $assignments,
        private readonly PdoRoleCatalog $roles,
        private readonly Moment $moment,
    ) {
    }

    /** @return list<RoleAssignment> affectations en vigueur maintenant */
    public function activeAssignments(string $tenantId, string $personId): array
    {
        return $this->assignments->activeForPerson($tenantId, $personId, $this->moment->iso());
    }

    /**
     * Codes de rôle exercés dans l'établissement (ou au niveau client si null).
     *
     * @return list<string>
     */
    public function roleCodes(string $tenantId, string $personId, ?string $establishmentId): array
    {
        $codes = [];
        foreach ($this->activeAssignments($tenantId, $personId) as $assignment) {
            if ($assignment->appliesTo($establishmentId)) {
                $codes[$assignment->role] = $assignment->role;
            }
        }

        return array_values($codes);
    }

    /** @return list<string> */
    public function permissions(string $tenantId, string $personId, ?string $establishmentId): array
    {
        return $this->roles->permissionsOf($this->roleCodes($tenantId, $personId, $establishmentId));
    }

    public function can(string $tenantId, string $personId, string $permission, ?string $establishmentId): bool
    {
        return in_array($permission, $this->permissions($tenantId, $personId, $establishmentId), true);
    }

    /** Vrai si la permission est accordée dans au moins un périmètre (contrôle préalable, avant toute validation). */
    public function canSomewhere(string $tenantId, string $personId, string $permission): bool
    {
        $codes = array_values(array_unique(array_map(static fn (RoleAssignment $a): string => $a->role, $this->activeAssignments($tenantId, $personId))));

        return $codes !== [] && in_array($permission, $this->roles->permissionsOf($codes), true);
    }

    public function hasTenantWideRole(string $tenantId, string $personId): bool
    {
        foreach ($this->activeAssignments($tenantId, $personId) as $assignment) {
            if ($assignment->isTenantWide()) {
                return true;
            }
        }

        return false;
    }

    public function hasRole(string $tenantId, string $personId, string $role): bool
    {
        foreach ($this->activeAssignments($tenantId, $personId) as $assignment) {
            if ($assignment->role === $role) {
                return true;
            }
        }

        return false;
    }

    /** Administrateur de plateforme : rôle global, rattaché au client technique `platform`. */
    public function isPlatformAdmin(string $tenantId, string $personId): bool
    {
        return $tenantId === Role::PLATFORM_TENANT && $this->hasRole($tenantId, $personId, Role::PLATFORM_ADMIN);
    }

    /** Vrai si la personne exerce actuellement au moins un rôle sensible (double authentification obligatoire). */
    public function hasSensitiveRole(string $tenantId, string $personId): bool
    {
        $catalog = $this->roles->all();
        foreach ($this->activeAssignments($tenantId, $personId) as $assignment) {
            if (isset($catalog[$assignment->role]) && $catalog[$assignment->role]->sensitive) {
                return true;
            }
        }

        return false;
    }

    /**
     * Rôles sensibles exercés (libellé selon la langue) avec leur établissement, pour le bandeau « obligatoire pour votre rôle ».
     *
     * @return list<array{role: string, label: string, establishment_id: string|null}>
     */
    public function sensitiveRoles(string $tenantId, string $personId, string $locale): array
    {
        $catalog = $this->roles->all();
        $result = [];
        foreach ($this->activeAssignments($tenantId, $personId) as $assignment) {
            $role = $catalog[$assignment->role] ?? null;
            if ($role !== null && $role->sensitive) {
                $result[] = ['role' => $role->code, 'label' => $role->label($locale), 'establishment_id' => $assignment->establishmentId];
            }
        }

        return $result;
    }
}
