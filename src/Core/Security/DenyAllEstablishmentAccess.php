<?php

declare(strict_types=1);

namespace Scholaris\Core\Security;

/** Refus par défaut. */
final class DenyAllEstablishmentAccess implements EstablishmentAccess
{
    public function canAccess(Principal $principal, string $establishmentId): bool
    {
        return false;
    }
}
