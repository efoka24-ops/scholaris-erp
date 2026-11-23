<?php

declare(strict_types=1);

namespace Tests\Support\Nursery;

use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Socle\Identity\Contracts\PermissionScopes;
use Scholaris\Modules\Socle\Identity\Contracts\Scope;

/**
 * Doublure de PermissionChecker ET de PermissionScopes : « client:utilisateur => permission => établissement + périmètre ».
 * Refus par défaut ; une permission donnée sans périmètre précis couvre tout l'établissement.
 */
final class NurseryPermissions implements PermissionChecker, PermissionScopes
{
    /** @var array<string, array<string, array<string, list<Scope>>>> */
    private array $grants = [];

    /** Donne la permission sur un établissement ; sans section, c'est tout l'établissement. */
    public function grant(string $tenantId, string $userId, string $permission, string $establishmentId, ?string $sectionId = null): void
    {
        $scope = $sectionId === null ? new Scope(Scope::ESTABLISHMENT, $establishmentId, 'test') : new Scope(Scope::CLASS_GROUP, $sectionId, 'test');
        $this->grants[$tenantId . ':' . $userId][$permission][$establishmentId][] = $scope;
    }

    public function can(Principal $principal, string $permission, ?string $establishmentId = null): bool
    {
        return $establishmentId !== null && isset($this->grants[$principal->tenantId . ':' . $principal->userId][$permission][$establishmentId]);
    }

    public function scopesFor(Principal $principal, string $permission, string $establishmentId): array
    {
        return $this->grants[$principal->tenantId . ':' . $principal->userId][$permission][$establishmentId] ?? [];
    }
}
