<?php

declare(strict_types=1);

namespace Tests\Support\Messaging;

use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;

/** Permissions explicites par utilisateur. Refus par défaut. */
final class StubPermissions implements PermissionChecker
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
