<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Reporting\Contracts;

/**
 * Fournisseur d'un indicateur agrégé par territoire (PP4). Déclaré par une application sous la clé de liste
 * `reporting.territory_aggregates.<application>` ; la commande `reporting:aggregate-territories` l'interroge pour chaque
 * établissement du client puis additionne les effectifs dans le territoire de rattachement et tous ses parents.
 */
interface TerritoryAggregateProvider
{
    /** Clé stable de l'indicateur (80 caractères au plus), par exemple `public_schools.enrolment`. */
    public function indicator(): string;

    /**
     * Effectifs d'un établissement à la date de référence, par case (clé de 80 caractères au plus, par exemple `level=CP;sex=F`).
     * Les cases forment une partition de l'indicateur : leur somme est le total de l'établissement.
     *
     * @return array<string, int>
     */
    public function countsFor(string $tenantId, string $establishmentId, string $referenceDate): array;
}
