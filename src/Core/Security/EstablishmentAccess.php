<?php

declare(strict_types=1);

namespace Scholaris\Core\Security;

/** Dit si une personne peut agir dans un établissement. Implémenté par le module Identity (rôles et périmètres). */
interface EstablishmentAccess
{
    public function canAccess(Principal $principal, string $establishmentId): bool;
}
