<?php

declare(strict_types=1);

namespace Tests\Support\Reporting;

use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;

/** Doublure de PermissionChecker : refus par défaut ; un droit sans établissement vaut pour tous, sinon pour celui-là. */
final class Permissions implements PermissionChecker
{
    /** @var array<string, true> */
    private array $grants = [];

    public function grant(string $userId, string $permission, ?string $establishmentId = null): void
    {
        $this->grants[$userId . '|' . $permission . '|' . ($establishmentId ?? '*')] = true;
    }

    public function can(Principal $principal, string $permission, ?string $establishmentId = null): bool
    {
        $base = $principal->userId . '|' . $permission . '|';

        return isset($this->grants[$base . '*']) || ($establishmentId !== null && isset($this->grants[$base . $establishmentId]));
    }
}
