<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Policies;

use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Primary\Support\Perimeter;
use Scholaris\Modules\Socle\Identity\Contracts\PermissionScopes;
use Scholaris\Modules\Socle\Identity\Contracts\Scope;
use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitTree;

/**
 * Périmètre d'une permission dans l'établissement : tout l'établissement, ou les structures couvertes par les affectations de la
 * personne. Une section (`org_unit`) se développe en toutes ses structures, un niveau (`structure`) en lui-même et ses classes, une
 * classe (`class`) reste telle quelle. Le développement passe par le contrat OrgUnitTree, borné au client et à l'établissement.
 */
final class ScopeResolver
{
    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly PermissionScopes $scopes,
        private readonly OrgUnitTree $tree,
    ) {
    }

    public function resolve(Principal $principal, string $permission, string $establishmentId): Perimeter
    {
        if (!$this->permissions->can($principal, $permission, $establishmentId)) {
            return new Perimeter($principal->tenantId, $establishmentId, false);
        }
        $whole = false;
        $classes = [];
        foreach ($this->scopes->scopesFor($principal, $permission, $establishmentId) as $scope) {
            if ($scope->type === Scope::ESTABLISHMENT) {
                $whole = true;
            } elseif ($scope->type === Scope::CLASS_GROUP) {
                $classes[] = $scope->id;
            } elseif ($scope->type === Scope::STRUCTURE) {
                array_push($classes, ...$this->tree->structureDescendantsOf($principal->tenantId, $establishmentId, $scope->id));
            } elseif ($scope->type === Scope::ORG_UNIT) {
                array_push($classes, ...$this->tree->structuresOf($principal->tenantId, $establishmentId, $scope->id));
            }
        }

        return new Perimeter($principal->tenantId, $establishmentId, $whole, array_values(array_unique($classes)));
    }
}
