<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Dashboards\Domain;

/**
 * Valeurs de départ des tableaux de bord (D12) : cibles des objectifs O1 à O7 reprises du CDC (§ Objectifs), délai de remise des
 * bulletins, moyenne de passage du rapport annuel et tranches d'âge du rapport de rentrée. Toutes « à confirmer » et modifiables
 * par paramètre, sans développement :
 *  - `secondary.dashboards.targets` (objet code => cible, fusionné avec TARGETS) ;
 *  - `secondary.dashboards.settings` (objet, fusionné avec SETTINGS).
 */
final class DashboardDefaults
{
    public const SOURCE = 'cdc';
    public const CONFIRMATION = 'to_confirm';

    /**
     * Cible et sens de chaque objectif : `max` = la valeur doit rester au plus égale à la cible, `min` = au moins égale,
     * `drop` = baisse en pourcentage au moins égale à la cible par rapport à la période de référence.
     *
     * @var array<string, array{target: int|float, direction: string, unit: string}>
     */
    public const TARGETS = [
        'O1' => ['target' => 0, 'direction' => 'max', 'unit' => 'count'],
        'O2' => ['target' => 100, 'direction' => 'min', 'unit' => 'percent'],
        'O3' => ['target' => 100, 'direction' => 'min', 'unit' => 'percent'],
        'O4' => ['target' => 90, 'direction' => 'min', 'unit' => 'percent'],
        'O5' => ['target' => 0, 'direction' => 'max', 'unit' => 'count'],
        'O6' => ['target' => 100, 'direction' => 'min', 'unit' => 'percent'],
        'O7' => ['target' => 30, 'direction' => 'drop', 'unit' => 'hours_per_student'],
    ];

    /**
     * `report_card_days` : jours après la fin de la dernière séquence du trimestre pour remettre les bulletins (O2) ;
     * `passing_average` : moyenne annuelle comptée « admis » dans le rapport annuel DOC-34 ;
     * `age_bands` : bornes supérieures incluses des tranches d'âge du rapport de rentrée DOC-32 (la dernière tranche est ouverte).
     *
     * @var array{report_card_days: int, passing_average: float, age_bands: list<int>}
     */
    public const SETTINGS = [
        'report_card_days' => 5,
        'passing_average' => 10.0,
        'age_bands' => [11, 14, 17, 20],
    ];

    private function __construct()
    {
    }
}
