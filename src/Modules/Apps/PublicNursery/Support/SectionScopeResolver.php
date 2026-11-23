<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicNursery\Support;

use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Socle\Identity\Contracts\PermissionScopes;
use Scholaris\Modules\Socle\Identity\Contracts\Scope;
use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitTree;

/**
 * Périmètre de sections d'un utilisateur : pour chacune des permissions qu'il détient dans l'établissement (PermissionChecker),
 * ses affectations de rôle (PermissionScopes, S15) sont développées par OrgUnitTree en identifiants de sections. Un périmètre
 * d'établissement couvre tout ; une unité couvre les structures rattachées ; une filière ses niveaux et groupes ; une classe
 * elle-même. Refus par défaut : aucune permission = périmètre vide.
 */
final class SectionScopeResolver
{
    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly PermissionScopes $scopes,
        private readonly OrgUnitTree $tree,
    ) {
    }

    /** @param list<string> $anyOf permissions qui ouvrent la route : leurs périmètres s'additionnent */
    public function resolve(Principal $principal, array $anyOf, string $establishmentId): SectionScope
    {
        $tenantId = $principal->tenantId;
        $ids = [];
        foreach ($anyOf as $permission) {
            if (!$this->permissions->can($principal, $permission, $establishmentId)) {
                continue;
            }
            foreach ($this->scopes->scopesFor($principal, $permission, $establishmentId) as $scope) {
                switch ($scope->type) {
                    case Scope::ESTABLISHMENT:
                        return SectionScope::wholeEstablishment();
                    case Scope::ORG_UNIT:
                        $ids = array_merge($ids, $this->tree->structuresOf($tenantId, $establishmentId, $scope->id));
                        break;
                    case Scope::STRUCTURE:
                    case Scope::CLASS_GROUP:
                        $descendants = $this->tree->structureDescendantsOf($tenantId, $establishmentId, $scope->id);
                        $ids = array_merge($ids, $descendants === [] && $scope->type === Scope::CLASS_GROUP ? [$scope->id] : $descendants);
                        break;
                }
            }
        }

        return new SectionScope(...$ids);
    }
}
