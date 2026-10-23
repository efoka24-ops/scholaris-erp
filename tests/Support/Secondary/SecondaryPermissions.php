<?php

declare(strict_types=1);

namespace Tests\Support\Secondary;

use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;

/**
 * Doublure de PermissionChecker : « client:utilisateur => permission => établissements » ; une permission accordée sans
 * établissement (administrateur de plateforme) est vérifiée avec `$establishmentId = null`. Refus par défaut.
 */
final class SecondaryPermissions implements PermissionChecker
{
    private const ANYWHERE = '';

    /** @var array<string, array<string, list<string>>> */
    private array $grants = [];

    public function grant(string $tenantId, string $userId, string $permission, ?string $establishmentId = null): void
    {
        $this->grants[$tenantId . ':' . $userId][$permission][] = $establishmentId ?? self::ANYWHERE;
    }

    public function can(Principal $principal, string $permission, ?string $establishmentId = null): bool
    {
        return in_array($establishmentId ?? self::ANYWHERE, $this->grants[$principal->tenantId . ':' . $principal->userId][$permission] ?? [], true);
    }
}
