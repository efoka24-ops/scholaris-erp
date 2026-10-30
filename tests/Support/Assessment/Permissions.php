<?php

declare(strict_types=1);

namespace Tests\Support\Assessment;

use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;

/** Doublure de PermissionChecker : refus par défaut, droits accordés par utilisateur. */
final class Permissions implements PermissionChecker
{
    /** @var array<string, array<string, true>> */
    private array $grants = [];

    public function grant(string $userId, string ...$permissions): void
    {
        foreach ($permissions as $permission) {
            $this->grants[$userId][$permission] = true;
        }
    }

    public function can(Principal $principal, string $permission, ?string $establishmentId = null): bool
    {
        return isset($this->grants[$principal->userId][$permission]);
    }
}
