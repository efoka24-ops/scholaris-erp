<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Contracts;

/**
 * Sexe et date de naissance de plusieurs personnes en une lecture (statistiques réglementaires et tableaux de bord) : les autres
 * modules ne lisent jamais `people` et ne bouclent pas personne par personne. Fourni par People ; borné au client.
 */
interface PersonDemographics
{
    /**
     * @param list<string> $personIds
     * @return array<string, array{gender: ?string, birth_date: ?string}> par identifiant de personne connu de ce client (genre « male »/« female », date AAAA-MM-JJ)
     */
    public function ofPersons(string $tenantId, array $personIds): array;
}
