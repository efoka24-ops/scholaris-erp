<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Contracts;

/**
 * Point d'extension facultatif d'une RuleStrategy (SS3, PS1b) : calcul d'une période qui a des périodes enfants
 * (année → trimestres → séquences, ou trimestre → séquences). ResultService calcule d'abord chaque enfant (récursivement),
 * puis appelle aggregatePeriods pour chaque élève avec les résumés des enfants DANS L'ORDRE des périodes (rang, puis date
 * de début). Dans un résumé, `units` est indexé par identifiant de matière ; chaque entrée porte au moins `average`
 * (moyenne de la matière sur l'enfant, ou null), `code` et `name`, plus le détail rendu par la stratégie pour l'enfant.
 *
 * Le résultat a la même forme : `units` indexé par identifiant de matière ({average, ...}) ; `extras` (facultatif) est
 * enregistré dans les valeurs calculées du résultat. Une stratégie qui n'implémente pas cette interface garde le
 * comportement historique : la période parente est calculée sur ses propres épreuves.
 *
 * Fonction pure, sans lecture de base.
 */
interface PeriodAggregatingStrategy
{
    /**
     * @param list<array{period_id: string, average: float|null, credits_earned: int, credits_total: int, units: array<string, array<string, mixed>>}> $periods
     * @return array{average: float|null, credits_earned: int, credits_total: int, units: array<string, array<string, mixed>>, extras?: array<string, mixed>}
     */
    public function aggregatePeriods(CalculationRules $rules, array $periods): array;
}
