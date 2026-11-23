<?php

declare(strict_types=1);

namespace Scholaris\Core\Security;

/**
 * Dit si une personne détient une permission (ex. « organisation.manage ») dans son client,
 * éventuellement limitée à un établissement. Implémenté par le module Identity ; refus par défaut.
 */
interface PermissionChecker
{
    public function can(Principal $principal, string $permission, ?string $establishmentId = null): bool;
}
