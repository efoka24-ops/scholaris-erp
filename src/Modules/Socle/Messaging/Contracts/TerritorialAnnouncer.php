<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Contracts;

/**
 * Annonce d'une autorité territoriale (inspection, délégation) : publiée dans chaque établissement rattaché au territoire
 * (ou à l'un de ses descendants) à la date du jour, et dans aucun autre (PP12). L'appelant contrôle les droits et journalise.
 * Fourni par Messaging.
 */
interface TerritorialAnnouncer
{
    /**
     * @return list<string> identifiants des établissements où l'annonce a été publiée (vide si le territoire est inconnu)
     */
    public function announce(string $tenantId, string $territoryId, string $title, string $body, string $actorId, ?string $expiresAt = null): array;
}
