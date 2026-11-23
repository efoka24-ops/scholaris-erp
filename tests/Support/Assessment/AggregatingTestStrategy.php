<?php

declare(strict_types=1);

namespace Tests\Support\Assessment;

use Scholaris\Modules\Socle\Assessment\Contracts\CalculationRules;
use Scholaris\Modules\Socle\Assessment\Contracts\PeriodAggregatingStrategy;
use Scholaris\Modules\Socle\Assessment\Contracts\RuleStrategy;

/**
 * Stratégie de test (SS3) écrite comme une application l'écrirait : moyenne simple des notes ramenées au barème par
 * matière, moyenne générale pondérée par les coefficients ; une période parente = moyenne simple de ses enfants, par
 * matière et en général. Enregistre l'ordre des enfants reçus.
 */
final class AggregatingTestStrategy implements RuleStrategy, PeriodAggregatingStrategy
{
    public const KEY = 'aggregating_test';

    /** @var list<list<string>> périodes enfants reçues, appel par appel */
    public static array $received = [];

    public function key(): string
    {
        return self::KEY;
    }

    public function optionErrors(array $options): array
    {
        return [];
    }

    public function computeLearner(CalculationRules $rules, array $units, string $session): array
    {
        $subjects = [];
        $sum = 0.0;
        $weights = 0.0;
        foreach ($units as $unit) {
            $values = [];
            foreach ($unit['groups'] as $group) {
                foreach ($group['entries'] as $entry) {
                    if ($entry['value'] !== null && $entry['max'] > 0.0) {
                        $values[] = $entry['value'] / $entry['max'] * $rules->scale;
                    }
                }
            }
            $average = $values === [] ? null : $rules->round(array_sum($values) / count($values));
            $subjects[$unit['subject_id']] = $average;
            if ($average !== null) {
                $sum += $average * $unit['coefficient'];
                $weights += $unit['coefficient'];
            }
        }
        $overall = $weights > 0.0 ? $rules->round($sum / $weights) : null;

        return ['average' => $overall, 'subjects' => $subjects, 'credits_earned' => 0, 'credits_total' => 0, 'mention' => null, 'deliberation_proposal' => 'admitted'];
    }

    public function decideYear(CalculationRules $rules, array $periods, array $context): array
    {
        $aggregate = $this->aggregatePeriods($rules, $periods);

        return ['average' => $aggregate['average'], 'credits_earned' => 0, 'credits_total' => 0, 'decision' => 'admitted'];
    }

    public function aggregatePeriods(CalculationRules $rules, array $periods): array
    {
        self::$received[] = array_map(static fn (array $p): string => $p['period_id'], $periods);
        $averages = [];
        $bySubject = [];
        foreach ($periods as $period) {
            if ($period['average'] !== null) {
                $averages[] = $period['average'];
            }
            foreach ($period['units'] as $subjectId => $unit) {
                if (is_int($unit['average'] ?? null) || is_float($unit['average'] ?? null)) {
                    $bySubject[$subjectId][] = (float) $unit['average'];
                }
            }
        }
        $units = [];
        foreach ($bySubject as $subjectId => $values) {
            $units[$subjectId] = ['average' => $rules->round(array_sum($values) / count($values)), 'periods' => count($values)];
        }

        return [
            'average' => $averages === [] ? null : $rules->round(array_sum($averages) / count($averages)),
            'credits_earned' => 0, 'credits_total' => 0, 'units' => $units, 'extras' => ['children' => count($periods)],
        ];
    }
}
