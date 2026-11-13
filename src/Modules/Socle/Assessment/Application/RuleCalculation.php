<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Assessment\Contracts\RuleStrategy;
use Scholaris\Modules\Socle\Assessment\Domain\RuleEngine;
use Scholaris\Modules\Socle\Assessment\Domain\RuleSet;

/**
 * Calcul d'un apprenant avec les règles données (S6) : moteur standard du socle, ou stratégie enregistrée que les
 * règles nomment. La sortie d'une stratégie est contrôlée et normalisée ; une stratégie absente est une erreur (on
 * n'applique jamais d'autres règles que celles déclarées). Fonctions pures, sans base.
 *
 * @phpstan-import-type Unit from RuleStrategy
 * @phpstan-import-type LearnerResult from RuleStrategy
 * @phpstan-import-type PeriodSummary from RuleStrategy
 * @phpstan-import-type YearDecision from RuleStrategy
 */
final class RuleCalculation
{
    public function __construct(
        private readonly RuleEngine $engine,
        private readonly RuleStrategies $strategies,
    ) {
    }

    /**
     * @param list<Unit> $units
     * @return LearnerResult
     */
    public function compute(RuleSet $rules, array $units, string $session = 'normal'): array
    {
        if ($rules->strategy === RuleSet::STANDARD) {
            return $this->engine->compute($rules, $units);
        }

        return $this->normalise($this->strategy($rules)->computeLearner($rules->toCalculationRules(), $units, $session), $rules);
    }

    /**
     * Décision de fin d'année. Moteur standard : moyenne des périodes pondérée par leurs crédits (sinon moyenne simple),
     * admis si elle atteint la moyenne de passage.
     *
     * @param list<PeriodSummary> $periods
     * @param array<string, mixed> $context
     * @return YearDecision
     */
    public function decideYear(RuleSet $rules, array $periods, array $context = []): array
    {
        if ($rules->strategy !== RuleSet::STANDARD) {
            $decision = $this->strategy($rules)->decideYear($rules->toCalculationRules(), $periods, $context);

            return [
                'average' => isset($decision['average']) ? (float) $decision['average'] : null,
                'credits_earned' => (int) $decision['credits_earned'],
                'credits_total' => (int) $decision['credits_total'],
                'decision' => (string) $decision['decision'],
                'extras' => $decision['extras'] ?? [],
            ];
        }

        $earned = 0;
        $total = 0;
        $byCredits = true;
        $sum = 0.0;
        $weights = 0.0;
        $plain = [];
        foreach ($periods as $period) {
            $earned += $period['credits_earned'];
            $total += $period['credits_total'];
            if ($period['average'] === null) {
                continue;
            }
            $plain[] = $period['average'];
            $byCredits = $byCredits && $period['credits_total'] > 0;
            $sum += $period['average'] * $period['credits_total'];
            $weights += $period['credits_total'];
        }
        $average = null;
        if ($plain !== []) {
            $average = $this->engine->round($rules, $byCredits && $weights > 0.0 ? $sum / $weights : array_sum($plain) / count($plain));
        }

        return [
            'average' => $average,
            'credits_earned' => $earned,
            'credits_total' => $total,
            'decision' => $rules->decisionLabel($average !== null && $average >= $rules->passMark ? 'admitted' : 'adjourned'),
            'extras' => [],
        ];
    }

    private function strategy(RuleSet $rules): RuleStrategy
    {
        return $this->strategies->find($rules->strategy)
            ?? throw HttpException::conflict(sprintf('La stratégie de calcul « %s » demandée par les règles n\'est pas disponible. Stratégies disponibles : %s.', $rules->strategy, implode(', ', $this->strategies->keys())));
    }

    /**
     * @param array<string, mixed> $raw
     * @return LearnerResult
     */
    private function normalise(array $raw, RuleSet $rules): array
    {
        $subjects = [];
        foreach ((array) ($raw['subjects'] ?? []) as $id => $average) {
            $subjects[(string) $id] = is_int($average) || is_float($average) ? (float) $average : null;
        }
        $units = [];
        foreach ((array) ($raw['units'] ?? []) as $id => $unit) {
            if (is_array($unit)) {
                $units[(string) $id] = $unit;
            }
        }
        $average = $raw['average'] ?? null;
        $proposal = $raw['deliberation_proposal'] ?? null;

        return [
            'average' => is_int($average) || is_float($average) ? (float) $average : null,
            'subjects' => $subjects,
            'credits_earned' => (int) ($raw['credits_earned'] ?? 0),
            'credits_total' => (int) ($raw['credits_total'] ?? 0),
            'mention' => isset($raw['mention']) && is_string($raw['mention']) ? $raw['mention'] : null,
            'deliberation_proposal' => is_string($proposal) && $proposal !== '' ? $proposal : $rules->decisionLabel('adjourned'),
            'units' => $units,
            'extras' => is_array($raw['extras'] ?? null) ? $raw['extras'] : [],
        ];
    }
}
