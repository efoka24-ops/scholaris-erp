<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Reporting\Contracts;

/**
 * Tableau de bord déclaré par une application (S21), servi par GET /dashboards/{key} à côté de direction, censeur et groupe
 * (qui restent prioritaires). Exemples IPES : `ecole`, `academique`, `tutelle`.
 *
 * Enregistrement : clé de liste du conteneur `reporting.dashboards.<application>` => [MaDefinition::class ou instance de
 * ConfiguredDashboard, ...]. Les indicateurs sont produits par les fournisseurs `reporting.indicators.*` ; un indicateur attendu
 * sans source est rendu « available:false ». Le paramètre de requête `org_unit_id` (école interne) est transmis aux fournisseurs
 * par IndicatorScope::$orgUnitId.
 */
interface DashboardDefinition
{
    /** Clé de l'URL (minuscules, chiffres, _ ou -). */
    public function key(): string;

    public function label(): string;

    /** Permission exigée dans l'établissement (ex. reporting.read, higher.dashboard.read). */
    public function permission(): string;

    /** @return array<string, string> indicateurs attendus : clé => libellé, dans l'ordre d'affichage */
    public function expected(): array;
}
