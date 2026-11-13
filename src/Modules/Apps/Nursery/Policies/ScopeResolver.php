<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Policies;

use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Nursery\Support\Perimeter;
use Scholaris\Modules\Socle\Identity\Contracts\PermissionScopes;
use Scholaris\Modules\Socle\Identity\Contracts\Scope;
use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitTree;

/**
 * Périmètre de sections d'une personne pour une permission : ses affectations de rôle (PermissionScopes) sont développées par
 * OrgUnitTree en identifiants de sections. Établissement : tout ; unité interne : les structures rattachées ; structure ou classe :
 * elle-même et ses descendantes. Refus par défaut : aucune permission = périmètre vide.
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
        $tenantId = $principal->tenantId;
        if (!$this->permissions->can($principal, $permission, $establishmentId)) {
            return new Perimeter($tenantId, $establishmentId, false);
        }
        $sections = [];
        foreach ($this->scopes->scopesFor($principal, $permission, $establishmentId) as $scope) {
            switch ($scope->type) {
                case Scope::ESTABLISHMENT:
                    return new Perimeter($tenantId, $establishmentId, true);
                case Scope::ORG_UNIT:
                    $sections = array_merge($sections, $this->tree->structuresOf($tenantId, $establishmentId, $scope->id));
                    break;
                case Scope::STRUCTURE:
                case Scope::CLASS_GROUP:
                    $descendants = $this->tree->structureDescendantsOf($tenantId, $establishmentId, $scope->id);
                    $sections = array_merge($sections, $descendants === [] ? [$scope->id] : $descendants);
                    break;
            }
        }

        return new Perimeter($tenantId, $establishmentId, false, array_values(array_unique($sections)));
    }
}
