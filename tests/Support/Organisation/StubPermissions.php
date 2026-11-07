<?php

declare(strict_types=1);

namespace Tests\Support\Organisation;

use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;

/**
 * Doublure configurable de PermissionChecker (le vrai est fourni par le module Identity).
 * Refus par défaut ; un droit accordé sans établissement vaut pour tout le client de la personne.
 */
final class StubPermissions implements PermissionChecker
{
    /** @var array<string, array<string, list<?string>>> utilisateur => permission => établissements (null = tout le client) */
    private array $grants = [];

    public function grant(string $userId, string $permission, ?string $establishmentId = null): void
    {
        $this->grants[$userId][$permission][] = $establishmentId;
    }

    public function can(Principal $principal, string $permission, ?string $establishmentId = null): bool
    {
        $scopes = $this->grants[$principal->userId][$permission] ?? [];

        return in_array(null, $scopes, true) || ($establishmentId !== null && in_array($establishmentId, $scopes, true));
    }
}
