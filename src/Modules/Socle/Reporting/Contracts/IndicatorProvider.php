<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Reporting\Contracts;

/**
 * Fournisseur d'indicateurs d'un module ou d'une application. Il ne lit QUE les contrats des autres modules.
 *
 * Enregistrement : une définition de conteneur (definitions.php du module) dont le nom commence par
 * `reporting.indicators.` et dont la valeur est la liste des classes de fournisseurs, par exemple
 * `'reporting.indicators.ipes' => [IpesVacationIndicators::class]`. Un indicateur attendu par un tableau de bord
 * qu'aucun fournisseur ne produit est rendu « available:false ».
 */
interface IndicatorProvider
{
    /** Clé de module (people, billing, attendance…) : le fournisseur est ignoré si le module est désactivé pour l'établissement. */
    public function module(): string;

    /** @return list<Indicator> */
    public function indicators(IndicatorScope $scope): array;
}
