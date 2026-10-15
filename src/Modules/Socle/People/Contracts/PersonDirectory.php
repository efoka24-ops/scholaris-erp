<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Contracts;

/** Annuaire des personnes d'un client. Fourni par le module People (S03). */
interface PersonDirectory
{
    public function find(string $tenantId, string $personId): ?PersonSummary;

    /**
     * @param list<string> $personIds
     * @return list<PersonSummary> uniquement les personnes de ce client
     */
    public function findMany(string $tenantId, array $personIds): array;

    /** @return list<PersonSummary> garants financiers (et tuteurs) d'un apprenant */
    public function guarantorsOf(string $tenantId, string $learnerId): array;

    /** @return list<PersonSummary> apprenants dont cette personne est garant financier, tous établissements du client */
    public function learnersOf(string $tenantId, string $guarantorId): array;
}
