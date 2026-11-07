<?php

declare(strict_types=1);

namespace Tests\Support\Documents;

use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;

/** Doublure de PermissionChecker : refus par défaut, droits accordés par utilisateur. */
final class DocPermissions implements PermissionChecker
{
    /** @var array<string, list<string>> */
    private array $grants = [];

    public function grant(string $userId, string ...$permissions): void
    {
        $this->grants[$userId] = array_merge($this->grants[$userId] ?? [], $permissions);
    }

    /** @var array<string, true> utilisateur|établissement refusés (périmètre territorial, PP15) */
    private array $denied = [];

    public function denyIn(string $userId, string $establishmentId): void
    {
        $this->denied[$userId . '|' . $establishmentId] = true;
    }

    public function can(Principal $principal, string $permission, ?string $establishmentId = null): bool
    {
        if ($establishmentId !== null && isset($this->denied[$principal->userId . '|' . $establishmentId])) {
            return false;
        }

        return in_array($permission, $this->grants[$principal->userId] ?? [], true);
    }
}
