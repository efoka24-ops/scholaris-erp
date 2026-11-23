<?php

declare(strict_types=1);

namespace Tests\Support\Scheduling;

use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;

/** Doublure de PermissionChecker : refus par défaut, droits accordés par utilisateur. */
final class Permissions implements PermissionChecker
{
    /** @var array<string, list<string>> */
    private array $grants = [];

    public function grant(string $userId, string ...$permissions): void
    {
        $this->grants[$userId] = [...($this->grants[$userId] ?? []), ...$permissions];
    }

    public function can(Principal $principal, string $permission, ?string $establishmentId = null): bool
    {
        return in_array($permission, $this->grants[$principal->userId] ?? [], true);
    }
}
