<?php

declare(strict_types=1);

namespace Scholaris\Core\Security;

/** Refus par défaut. */
final class DenyAllPermissions implements PermissionChecker
{
    public function can(Principal $principal, string $permission, ?string $establishmentId = null): bool
    {
        return false;
    }
}
