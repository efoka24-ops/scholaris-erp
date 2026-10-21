<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Policies;

use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Primary\Support\Perimeter;
use Scholaris\Modules\Socle\Identity\Contracts\PermissionScopes;
use Scholaris\Modules\Socle\Identity\Contracts\Scope;

final class ScopeResolver
{
    public function __construct(private readonly PermissionChecker $permissions, private readonly PermissionScopes $scopes)
    {
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
            }
        }

        return new Perimeter($principal->tenantId, $establishmentId, $whole, array_values(array_unique($classes)));
    }
}
