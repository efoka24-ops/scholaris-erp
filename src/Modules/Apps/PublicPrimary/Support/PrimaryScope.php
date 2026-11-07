<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicPrimary\Support;

use DateTimeImmutable;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Identity\Contracts\PermissionScopes;
use Scholaris\Modules\Socle\Identity\Contracts\Scope;
use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitTree;
use Scholaris\Modules\Socle\Organisation\Contracts\TerritoryDirectory;

/**
 * Contrôle de périmètre de la Primaire publique, recopié du mécanisme commun (PublicSchools/Policies/ScopeResolver : les applications ne
 * partagent que des Contracts) : PermissionChecker::can (l'établissement), puis PermissionScopes::scopesFor (PP2) développé en classes
 * (OrgUnitTree) et en territoires (TerritoryDirectory, lignée à l'instant présent : une affectation d'un autre arrondissement ne donne rien).
 *
 * Hors périmètre : 404 en lecture (l'existence est cachée), 403 journalisé en écriture.
 */
final class PrimaryScope
{
    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly PermissionScopes $scopes,
        private readonly OrgUnitTree $tree,
        private readonly TerritoryDirectory $territories,
        private readonly AuditLog $audit,
    ) {
    }

    public function reach(Principal $principal, string $permission, string $establishmentId): Reach
    {
        $tenantId = $principal->tenantId;
        if (!$this->permissions->can($principal, $permission, $establishmentId)) {
            return new Reach($tenantId, $establishmentId, $permission, false);
        }
        $whole = false;
        $classes = [];
        $territories = [];
        $roles = [];
        $now = (new DateTimeImmutable())->format(DATE_ATOM);
        foreach ($this->scopes->scopesFor($principal, $permission, $establishmentId) as $scope) {
            switch ($scope->type) {
                case Scope::ESTABLISHMENT:
                    $whole = true;
                    $roles[$scope->role] = $scope->role;
                    break;
                case Scope::TERRITORY:
                    if ($scope->tenantId === $tenantId && $scope->establishmentId === $establishmentId && $this->contains($tenantId, $scope->id, $establishmentId, $now)) {
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

        return new Reach($tenantId, $establishmentId, $permission, $whole, array_values(array_unique($classes)), array_values($territories), array_values($roles));
    }

    /**
     * Premier périmètre non vide parmi les permissions données (dans l'ordre).
     */
    public function first(Principal $principal, string $establishmentId, string ...$permissions): Reach
    {
        $reach = new Reach($principal->tenantId, $establishmentId, $permissions[0] ?? '', false);
        foreach ($permissions as $permission) {
            $reach = $this->reach($principal, $permission, $establishmentId);
            if (!$reach->isEmpty()) {
                return $reach;
            }
        }

        return $reach;
    }

    /** Lecture d'une classe dans l'école : 404 hors périmètre. */
    public function assertReadsClass(Reach $reach, string $classId): void
    {
        if ($reach->isEmpty() || !$reach->coversClass($classId)) {
            throw HttpException::notFound();
        }
    }

    /** Écriture sur une classe : 403 journalisé hors périmètre. */
    public function assertWritesClass(Principal $principal, Reach $reach, ?string $classId, string $objectType, string $objectId): void
    {
        if (!$reach->isEmpty() && ($reach->wholeSchool || ($classId !== null && $reach->coversClass($classId)))) {
            return;
        }
        $this->denied($principal, $reach, $objectType, $objectId);
    }

    /** Écriture réservée à l'école entière (directeur) : 403 journalisé sinon. */
    public function assertWholeSchool(Principal $principal, Reach $reach, string $objectType, string $objectId, string $message): void
    {
        if ($reach->wholeSchool) {
            return;
        }
        $this->denied($principal, $reach, $objectType, $objectId, $message);
    }

    /** @return list<string> types de territoire (`arrondissement`, `departement`, `region`…) des affectations du périmètre */
    public function territoryTypes(Reach $reach): array
    {
        $types = [];
        $now = (new DateTimeImmutable())->format(DATE_ATOM);
        foreach ($reach->territoryIds as $territoryId) {
            $info = $this->territories->find($reach->tenantId, $territoryId, $now);
            if ($info !== null) {
                $types[] = $info->type;
            }
        }

        return $types;
    }

    private function denied(Principal $principal, Reach $reach, string $objectType, string $objectId, string $message = 'Cette opération sort de votre périmètre (école, classe ou territoire).'): never
    {
        $this->audit->append($principal->tenantId, $principal->userId, 'public_primary.scope.denied', $objectType, $objectId, null, [
            'establishment_id' => $reach->establishmentId, 'permission' => $reach->permission,
        ]);

        throw HttpException::forbidden($message);
    }

    private function contains(string $tenantId, string $territoryId, string $establishmentId, string $at): bool
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
