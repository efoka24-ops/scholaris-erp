<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Grades\Domain\Strategy;

use Scholaris\Modules\Socle\Assessment\Contracts\CalculationRules;
use Scholaris\Modules\Socle\Assessment\Contracts\PeriodAggregatingStrategy;
use Scholaris\Modules\Socle\Assessment\Contracts\RuleStrategy;

/**
 * Stratégie de calcul `primary` : moyennes pondérées, ENTIÈREMENT paramétrées (EX-PRI-21, 22, 27, SC-PRI-4). Aucun barème, seuil,
 * poids, arrondi ni libellé n'est écrit ici : tout vient de la version de règles du niveau (CalculationRules : scale, passMark,
 * rounding, mentions, decisionLabels, absenceEffects) et de l'option `period_weights` (poids par période, 1 par défaut).
 *
 *  - computeLearner : moyenne d'une matière = moyenne pondérée (coefficient du groupe) des moyennes de groupe, chacune moyenne
 *    pondérée (poids de l'épreuve) des notes ramenées au barème ; moyenne générale = moyenne des matières pondérée par leur
 *    coefficient ; une absence suit l'effet déclaré de son code (zéro ou exclue) ; la session de rattrapage est ignorée.
 *  - aggregatePeriods (PS1b) : moyenne pondérée des périodes (séquences d'un trimestre, trimestres d'une année).
 *  - decideYear : proposition « admis » si la moyenne annuelle atteint la note de passage de la version, « fin de cycle » au niveau
 *    final (contexte `is_final_level`), sinon « ajourné » ; libellés des décisions pris des règles ; la décision du conseil prévaut.
 *
 * Fonctions pures, sans lecture de base.
 */
final class PrimaryRuleStrategy implements RuleStrategy, PeriodAggregatingStrategy
{
    public const KEY = 'primary';
    public const OPTION_PERIOD_WEIGHTS = 'period_weights';
    public const DECISION_ADMITTED = 'admitted';
    public const DECISION_ADJOURNED = 'adjourned';
    public const DECISION_CYCLE_END = 'cycle_end';

    public function key(): string
    {
        return self::KEY;
    }

    public function optionErrors(array $options): array
    {
        $errors = [];
        foreach (array_keys($options) as $name) {
            if ($name !== self::OPTION_PERIOD_WEIGHTS) {
                $errors[] = 'Option inconnue pour la stratégie primaire : « ' . $name . ' ». Seule « ' . self::OPTION_PERIOD_WEIGHTS . ' » est reconnue.';
            }
        }
        $weights = $options[self::OPTION_PERIOD_WEIGHTS] ?? [];
        if (!is_array($weights)) {
            return [...$errors, 'Les poids de période sont une table « identifiant de période => poids ».'];
        }
        foreach ($weights as $period => $weight) {
            if (!is_string($period) || (!is_int($weight) && !is_float($weight)) || $weight < 0) {
                $errors[] = 'Poids de période invalide pour « ' . (string) $period . ' » : un nombre positif ou nul est attendu.';
            }
        }

        return $errors;
    }

    public function computeLearner(CalculationRules $rules, array $units, string $session): array
    {
        $averages = [];
        $details = [];
        $sum = 0.0;
        $weights = 0.0;
        foreach ($units as $unit) {
            $average = $this->unitAverage($rules, $unit['groups']);
            $averages[$unit['subject_id']] = $average === null ? null : $rules->round($average);
            $details[$unit['subject_id']] = ['average' => $averages[$unit['subject_id']], 'coefficient' => $unit['coefficient']];
            if ($average !== null && $unit['coefficient'] > 0.0) {
                $sum += $average * $unit['coefficient'];
                $weights += $unit['coefficient'];
            }
        }
        $overall = $weights > 0.0 ? $rules->round($sum / $weights) : null;

        return [
            'average' => $overall,
            'subjects' => $averages,
            'credits_earned' => 0,
            'credits_total' => 0,
            'mention' => $overall === null ? null : $rules->mention($overall),
            'deliberation_proposal' => $rules->label($overall !== null && $overall >= $rules->passMark ? self::DECISION_ADMITTED : self::DECISION_ADJOURNED),
            'units' => $details,
        ];
    }

    public function aggregatePeriods(CalculationRules $rules, array $periods): array
    {
        $sum = 0.0;
        $weights = 0.0;
        $unitSums = [];
        foreach ($periods as $period) {
            $weight = $this->periodWeight($rules, $period['period_id']);
            if ($weight <= 0.0) {
                continue;
            }
            if ($period['average'] !== null) {
                $sum += $period['average'] * $weight;
                $weights += $weight;
            }
            foreach ($period['units'] as $unitId => $unit) {
                $value = $unit['average'] ?? null;
                if (is_int($value) || is_float($value)) {
                    $unitSums[$unitId]['sum'] = ($unitSums[$unitId]['sum'] ?? 0.0) + (float) $value * $weight;
                    $unitSums[$unitId]['weights'] = ($unitSums[$unitId]['weights'] ?? 0.0) + $weight;
                }
            }
        }
        $units = [];
        foreach ($unitSums as $unitId => $parts) {
            $units[$unitId] = ['average' => $rules->round($parts['sum'] / $parts['weights'])];
        }

        return [
            'average' => $weights > 0.0 ? $rules->round($sum / $weights) : null,
            'credits_earned' => 0,
            'credits_total' => 0,
            'units' => $units,
        ];
    }

    public function decideYear(CalculationRules $rules, array $periods, array $context): array
    {
        $aggregate = $this->aggregatePeriods($rules, $periods);
        $average = $aggregate['average'];
        if (($context['is_final_level'] ?? false) === true) {
            $decision = self::DECISION_CYCLE_END;
        } else {
            $decision = $average !== null && $average >= $rules->passMark ? self::DECISION_ADMITTED : self::DECISION_ADJOURNED;
        }

        return [
            'average' => $average,
            'credits_earned' => 0,
            'credits_total' => 0,
            'decision' => $rules->label($decision),
            'extras' => ['decision_code' => $decision, 'passing' => $average !== null && $average >= $rules->passMark],
        ];
    }

    /** @param list<array{coefficient: float, entries: list<array<string, mixed>>}> $groups */
    private function unitAverage(CalculationRules $rules, array $groups): ?float
    {
        $sum = 0.0;
        $weights = 0.0;
        foreach ($groups as $group) {
            $average = $this->groupAverage($rules, $group['entries']);
            if ($average === null) {
                continue;
            }
            $coefficient = $group['coefficient'] > 0.0 ? $group['coefficient'] : 1.0;
            $sum += $average * $coefficient;
            $weights += $coefficient;
        }

        return $weights > 0.0 ? $sum / $weights : null;
    }

    /** @param list<array<string, mixed>> $entries */
    private function groupAverage(CalculationRules $rules, array $entries): ?float
    {
        $sum = 0.0;
        $weights = 0.0;
        foreach ($entries as $entry) {
            if (($entry['session'] ?? 'normal') === 'resit') {
                continue;
            }
            $weight = (float) $entry['weight'];
            $normalised = $this->normalise($rules, $entry);
            if ($normalised === null || $weight <= 0.0) {
                continue;
            }
            $sum += $normalised * $weight;
            $weights += $weight;
        }

        return $weights > 0.0 ? $sum / $weights : null;
    }

    /** @param array<string, mixed> $entry */
    private function normalise(CalculationRules $rules, array $entry): ?float
    {
        $absence = $entry['absence'] ?? null;
        if (is_string($absence)) {
            $effect = $rules->absenceEffects[$absence] ?? ($entry['effect'] ?? null);

            return $effect === CalculationRules::EFFECT_ZERO ? 0.0 : null;
        }
        $value = $entry['value'] ?? null;
        $max = (float) ($entry['max'] ?? 0);
        if (!is_int($value) && !is_float($value) || $max <= 0.0) {
            return null;
        }

        return (float) $value / $max * $rules->scale;
    }

    private function periodWeight(CalculationRules $rules, string $periodId): float
    {
        $weights = $rules->option(self::OPTION_PERIOD_WEIGHTS, []);
        $weight = is_array($weights) ? ($weights[$periodId] ?? null) : null;

        return is_int($weight) || is_float($weight) ? (float) $weight : 1.0;
    }
}
