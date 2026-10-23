<?php

declare(strict_types=1);

namespace Tests\Support\Higher;

use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;

/** Doublure de PermissionChecker : « client:utilisateur => permission => établissements ». Refus par défaut. */
final class HigherPermissions implements PermissionChecker
{
    /** @var array<string, array<string, list<string>>> */
    private array $grants = [];

    public function grant(string $tenantId, string $userId, string $permission, string $establishmentId): void
    {
        $this->grants[$tenantId . ':' . $userId][$permission][] = $establishmentId;
    }

    public function can(Principal $principal, string $permission, ?string $establishmentId = null): bool
    {
        return $establishmentId !== null
            && in_array($establishmentId, $this->grants[$principal->tenantId . ':' . $principal->userId][$permission] ?? [], true);
    }
}
