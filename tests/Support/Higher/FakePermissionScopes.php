<?php

declare(strict_types=1);

namespace Tests\Support\Higher;

use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Socle\Identity\Contracts\PermissionScopes;
use Scholaris\Modules\Socle\Identity\Contracts\Scope;

/** Doublure de PermissionScopes (S15) : « client:utilisateur => établissement => permission => périmètres ». */
final class FakePermissionScopes implements PermissionScopes
{
    /** @var array<string, array<string, array<string, list<Scope>>>> */
    private array $scopes = [];

    public function add(string $tenantId, string $userId, string $establishmentId, string $permission, Scope $scope): void
    {
        $this->scopes[$tenantId . ':' . $userId][$establishmentId][$permission][] = $scope;
    }

    public function scopesFor(Principal $principal, string $permission, string $establishmentId): array
    {
        return $this->scopes[$principal->tenantId . ':' . $principal->userId][$establishmentId][$permission] ?? [];
    }
}
