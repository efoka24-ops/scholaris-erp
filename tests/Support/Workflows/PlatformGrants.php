<?php

declare(strict_types=1);

namespace Tests\Support\Workflows;

use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;

/** Doublure : le compte `padmin` du client technique `platform` a les permissions de plateforme listées ; les autres passent par la doublure du test. */
final class PlatformGrants implements PermissionChecker
{
    /** @param list<string> $platformPermissions */
    public function __construct(private readonly PermissionChecker $inner, private readonly array $platformPermissions)
    {
    }

    public function can(Principal $principal, string $permission, ?string $establishmentId = null): bool
    {
        if ($principal->tenantId === 'platform') {
            return $principal->userId === 'padmin' && in_array($permission, $this->platformPermissions, true);
        }

        return $this->inner->can($principal, $permission, $establishmentId);
    }
}
