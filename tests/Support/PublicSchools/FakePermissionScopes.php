<?php

declare(strict_types=1);

namespace Tests\Support\PublicSchools;

use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Socle\Identity\Contracts\PermissionScopes;
use Scholaris\Modules\Socle\Identity\Contracts\Scope;

/** Doublure de PermissionScopes : périmètres déclarés par utilisateur, identiques pour toutes les permissions. */
final class FakePermissionScopes implements PermissionScopes
{
    /** @var array<string, list<Scope>> */
    private array $scopes = [];

    public function give(string $userId, Scope ...$scopes): void
    {
        $this->scopes[$userId] = array_values($scopes);
    }

    public function scopesFor(Principal $principal, string $permission, string $establishmentId): array
    {
        return array_values(array_filter(
            $this->scopes[$principal->userId] ?? [],
            static fn (Scope $scope): bool => !$scope->isTerritory() || ($scope->tenantId === $principal->tenantId && $scope->establishmentId === $establishmentId),
        ));
    }
}
