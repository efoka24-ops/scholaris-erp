<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Pickup\Contracts;

/**
 * Journal des arrivées, tenu par la sortie sécurisée : une arrivée enregistrée à l'appel rend l'enfant « présent » pour la veille de
 * non-récupération et pour le registre du jour. Contrat interne de l'application, utilisé par le module d'appel.
 */
interface ArrivalJournal
{
    /**
     * Enregistre l'arrivée de l'enfant ce jour-là (idempotente par `$clientUuid`, et par enfant et jour).
     *
     * @return bool vrai si une arrivée a été ajoutée, faux si elle existait déjà
     */
    public function recordArrival(string $tenantId, string $establishmentId, string $learnerId, string $sectionId, string $day, string $arrivedAt, string $actorId, string $origin, ?string $clientUuid): bool;
}
