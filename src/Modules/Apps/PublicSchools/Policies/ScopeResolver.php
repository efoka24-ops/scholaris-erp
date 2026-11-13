<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Policies;

use DateTimeImmutable;
use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\PublicSchools\Support\Perimeter;
use Scholaris\Modules\Socle\Identity\Contracts\PermissionScopes;
use Scholaris\Modules\Socle\Identity\Contracts\Scope;
use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitTree;
use Scholaris\Modules\Socle\Organisation\Contracts\TerritoryDirectory;

/**
 * Premier temps du contrôle de périmètre : PermissionChecker::can (l'établissement), puis PermissionScopes::scopesFor (PP2),
 * développé par OrgUnitTree (classes) et TerritoryDirectory (territoires). Refus par défaut : permission absente = périmètre vide.
 *
 * Défense en profondeur (RG-TER-2) : une affectation territoriale n'est retenue que si l'école consultée est rattachée à ce
 * territoire (ou à l'un de ses descendants) à l'instant présent ; une affectation d'un autre arrondissement ne donne rien,
 * même si le résolveur d'Identity la rendait par erreur. Une affectation d'un autre client est ignorée.
 */
final class ScopeResolver
{
    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly PermissionScopes $scopes,
        private readonly OrgUnitTree $tree,
        private readonly TerritoryDirectory $territories,
    ) {
    }

    public function resolve(Principal $principal, string $permission, string $establishmentId, ?DateTimeImmutable $at = null): Perimeter
    {
        $tenantId = $principal->tenantId;
        if (!$this->permissions->can($principal, $permission, $establishmentId)) {
            return Perimeter::none($tenantId, $establishmentId, $permission);
        }

        $whole = false;
        $classes = [];
        $territories = [];
        $roles = [];
        $now = ($at ?? new DateTimeImmutable())->format(DATE_ATOM);
        foreach ($this->scopes->scopesFor($principal, $permission, $establishmentId) as $scope) {
            switch ($scope->type) {
                case Scope::ESTABLISHMENT:
                    $whole = true;
                    $roles[$scope->role] = $scope->role;
                    break;
                case Scope::TERRITORY:
                    if ($scope->tenantId === $tenantId && $scope->establishmentId === $establishmentId
                        && $this->territoryContains($tenantId, $scope->id, $establishmentId, $now)) {
                        $territories[$scope->id] = $scope->id;
                        $roles[$scope->role] = $scope->role;
                    }
                    break;
                case Scope::ORG_UNIT:
                    $classes = array_merge($classes, $this->tree->structuresOf($tenantId, $establishmentId, $scope->id));
                    $roles[$scope->role] = $scope->role;
                    break;
                case Scope::STRUCTURE:
                case Scope::CLASS_GROUP:
                    $descendants = $this->tree->structureDescendantsOf($tenantId, $establishmentId, $scope->id);
                    $classes = array_merge($classes, $descendants === [] ? [$scope->id] : $descendants);
                    $roles[$scope->role] = $scope->role;
                    break;
            }
        }

        return new Perimeter(
            $tenantId,
            $establishmentId,
            $permission,
            $whole,
            array_values(array_unique($classes)),
            array_values($territories),
            array_values($roles),
        );
    }

    /** L'école est-elle rattachée au territoire, directement ou par un de ses parents (lignée à l'instant `$at`) ? */
    private function territoryContains(string $tenantId, string $territoryId, string $establishmentId, string $at): bool
    {
        $own = $this->territories->territoryOf($tenantId, $establishmentId, $at);
        if ($own === null) {
            return false;
        }
        foreach ($this->territories->lineage($tenantId, $own->id, $at) as $node) {
            if ($node->id === $territoryId) {
                return true;
            }
        }

        return false;
    }
}
