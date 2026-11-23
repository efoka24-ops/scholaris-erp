<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Policies;

use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Higher\Support\Perimeter;
use Scholaris\Modules\Socle\Identity\Contracts\PermissionScopes;
use Scholaris\Modules\Socle\Identity\Contracts\Scope;
use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitTree;

/**
 * Premier temps du contrôle de périmètre : PermissionChecker::can (l'établissement), puis PermissionScopes::scopesFor (S15),
 * développé par OrgUnitTree : une école donne ses départements et toutes leurs structures ; un département ses structures ;
 * une filière ses niveaux et groupes ; un groupe lui-même. Refus par défaut : permission absente = périmètre vide.
 * L'école d'une unité est sa racine (école > département) ; l'école d'une structure est celle de son unité de rattachement.
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
            return Perimeter::none($tenantId, $establishmentId);
        }

        $whole = false;
        $units = [];
        $structures = [];
        $schools = [];
        $roles = [];
        foreach ($this->scopes->scopesFor($principal, $permission, $establishmentId) as $scope) {
            $roles[$scope->role] = $scope->role;
            switch ($scope->type) {
                case Scope::ESTABLISHMENT:
                    $whole = true;
                    break;
                case Scope::ORG_UNIT:
                    $descendants = $this->tree->descendantsOf($tenantId, $establishmentId, $scope->id);
                    if ($descendants === []) {
                        break;
                    }
                    $units = array_merge($units, $descendants);
                    $structures = array_merge($structures, $this->tree->structuresOf($tenantId, $establishmentId, $scope->id));
                    $schools[] = $this->schoolOf($tenantId, $establishmentId, $scope->id);
                    break;
                case Scope::STRUCTURE:
                case Scope::CLASS_GROUP:
                    $descendants = $this->tree->structureDescendantsOf($tenantId, $establishmentId, $scope->id);
                    $structures = array_merge($structures, $descendants === [] && $scope->type === Scope::CLASS_GROUP ? [$scope->id] : $descendants);
                    $unit = $this->tree->structure($tenantId, $establishmentId, $scope->id)?->orgUnitId;
                    if ($unit !== null) {
                        $schools[] = $this->schoolOf($tenantId, $establishmentId, $unit);
                    }
                    break;
            }
        }

        return new Perimeter(
            $tenantId,
            $establishmentId,
            $whole,
            self::unique($units),
            self::unique($structures),
            self::unique($schools),
            array_values($roles),
        );
    }

    private function schoolOf(string $tenantId, string $establishmentId, string $orgUnitId): string
    {
        $ancestors = $this->tree->ancestorsOf($tenantId, $establishmentId, $orgUnitId);

        return $ancestors === [] ? $orgUnitId : $ancestors[count($ancestors) - 1];
    }

    /**
     * @param list<string> $ids
     * @return list<string>
     */
    private static function unique(array $ids): array
    {
        return array_values(array_unique($ids));
    }
}
