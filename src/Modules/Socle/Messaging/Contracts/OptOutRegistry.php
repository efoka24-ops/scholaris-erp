<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Contracts;

/**
 * Désinscription d'une personne des messages NON essentiels, par canal (RG-COM-2). Les messages dont le modèle de plateforme
 * est de catégorie « essential » (sécurité, fermeture) sont toujours livrés. Fourni par Messaging.
 */
interface OptOutRegistry
{
    public function optOut(string $tenantId, string $personId, string $channel): void;

    /** Annule la désinscription (idempotent). */
    public function optIn(string $tenantId, string $personId, string $channel): void;

    public function isOptedOut(string $tenantId, string $personId, string $channel): bool;
}
