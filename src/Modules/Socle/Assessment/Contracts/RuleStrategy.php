<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Contracts;

/**
 * Stratégie de calcul enregistrable (S6) : remplace le moteur standard pour les règles qui la nomment (clé « strategy »
 * d'une version de règles ou paramètre assessment.strategy). Une application l'enregistre dans le conteneur sous une
 * clé `assessment.rule_strategies.<module>` (liste de classes). Fonctions pures : aucune lecture de base.
 *
 * Données d'un apprenant : une liste d'unités (matières, UE) faites de groupes (éléments, EC), chacun portant ses notes.
 * Chaque note indique le type de l'épreuve (kind : cc, exam, resit...), sa session (normal, resit) et l'effet déclaré
 * de son code d'absence (S1). Les notes de session « resit » ne sont fournies que pour un calcul de rattrapage.
 *
 * @phpstan-type Entry array{value: float|null, max: float, weight: float, absence: string|null, effect: string|null, kind: string, session: string}
 * @phpstan-type Group array{element_id: string|null, code: string|null, coefficient: float, credits: int, entries: list<Entry>}
 * @phpstan-type Unit array{subject_id: string, code: string|null, name: string|null, kind: string, coefficient: float, credits: int, groups: list<Group>}
 * @phpstan-type LearnerResult array{average: float|null, subjects: array<string, float|null>, credits_earned: int, credits_total: int, mention: string|null, deliberation_proposal: string, units?: array<string, array<string, mixed>>, extras?: array<string, mixed>}
 * @phpstan-type PeriodSummary array{period_id: string, average: float|null, credits_earned: int, credits_total: int, units: array<string, array<string, mixed>>}
 * @phpstan-type YearDecision array{average: float|null, credits_earned: int, credits_total: int, decision: string, extras?: array<string, mixed>}
 */
interface RuleStrategy
{
    /** Clé de la stratégie, celle que les règles nomment (minuscules, chiffres, « _ »). « standard » est réservée. */
    public function key(): string;

    /**
     * Options refusées, en français ; vide si elles sont acceptables. Appelée à la publication d'une version de règles.
     *
     * @param array<string, mixed> $options
     * @return list<string>
     */
    public function optionErrors(array $options): array;

    /**
     * Résultat d'un apprenant pour une période et une session. « subjects » : moyenne par unité ; « units » : détail
     * par unité (lettre, points, statut acquise/compensée/non acquise...) ; « extras » : valeurs propres (MGP...).
     *
     * @param list<Unit> $units
     * @param string $session normal | resit
     * @return LearnerResult
     */
    public function computeLearner(CalculationRules $rules, array $units, string $session): array;

    /**
     * Décision de fin d'année à partir des résultats des périodes (semestres).
     *
     * @param list<PeriodSummary> $periods
     * @param array<string, mixed> $context données de l'application (inscriptions antérieures au niveau, crédits cumulés, dernière année...)
     * @return YearDecision
     */
    public function decideYear(CalculationRules $rules, array $periods, array $context): array;
}
