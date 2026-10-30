<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Councils\Domain;

use Scholaris\Modules\Socle\Assessment\Contracts\CalculationRules;
use Scholaris\Modules\Socle\Assessment\Contracts\PeriodAggregatingStrategy;
use Scholaris\Modules\Socle\Assessment\Contracts\RuleStrategy;

/**
 * Stratégie de calcul `secondary` (T812, spec 005 §7) pour les 7 types d'établissement : ENTIÈREMENT paramétrée par la version
 * de règles de l'établissement (barème, note de passage, arrondi, libellés de décision, options ci-dessous). Aucun coefficient,
 * seuil, barème ni règle de passage n'est écrit ici (RG-SEC-4) ; le jeu d'exemple du CDC est dans Defaults (« à confirmer »).
 * Les rangs (ex æquo au même rang, suivant décalé) et les statistiques par matière restent au socle (RuleEngine::ranks,
 * assessment.subject_ranks).
 *
 *  - computeLearner (séquence, période feuille) : note de séquence d'une matière = moyenne pondérée (poids des épreuves) des
 *    notes ramenées au barème ; absence selon l'effet déclaré de son code (zéro ou exclue) ; moyenne générale = Σ (moyenne ×
 *    coefficient) ÷ Σ coefficients des matières ÉVALUÉES (une matière non évaluée est exclue avec son coefficient et signalée).
 *  - aggregatePeriods (trimestre, année ; récursif) : moyenne d'une matière = moyenne pondérée (`period_weights`, 1 par défaut)
 *    de ses moyennes sur les périodes enfants ; moyenne générale selon `aggregation_modes[niveau - 1]` (dernier mode répété) :
 *    `subjects` = Σ (moyenne × coefficient) ÷ Σ coefficients, `periods` = moyenne pondérée des moyennes générales des enfants.
 *  - `round_each_step` (vrai par défaut) : arrondi de la version à chaque étape ; faux : valeurs brutes transmises d'un niveau à
 *    l'autre, arrondi à l'affichage seulement.
 *  - Valeurs calculées (`extras`) : niveau (`depth`, 0 = séquence), matières non évaluées, matières sous `subject_alert_below`,
 *    matières de pratique sous `practical.min`, proposition du travail (`work_proposals`) et, au niveau `promotion.at_depth`,
 *    proposition de passage (PromotionPolicy). Toujours des propositions : le conseil décide.
 *  - decideYear : agrégat des périodes reçues + proposition de passage avec le contexte (redoublant, âge limite).
 *
 * Fonctions pures, sans lecture de base.
 */
final class SecondaryStrategy implements RuleStrategy, PeriodAggregatingStrategy
{
    public const KEY = 'secondary';
    public const MODE_SUBJECTS = 'subjects';
    public const MODE_PERIODS = 'periods';
    public const OPTION_PERIOD_WEIGHTS = 'period_weights';
    public const OPTION_ROUND_EACH_STEP = 'round_each_step';
    public const OPTION_MODES = 'aggregation_modes';
    public const OPTION_SUBJECT_ALERT = 'subject_alert_below';
    public const OPTION_EXAMPLE = 'example';

    private const KNOWN = [
        self::OPTION_EXAMPLE, self::OPTION_PERIOD_WEIGHTS, self::OPTION_ROUND_EACH_STEP, self::OPTION_MODES, self::OPTION_SUBJECT_ALERT,
        WorkProposals::OPTION, WorkProposals::OPTION_BLOCKED, PracticalSubjects::OPTION, PromotionPolicy::OPTION,
    ];

    public function key(): string
    {
        return self::KEY;
    }

    public function optionErrors(array $options): array
    {
        $errors = [];
        foreach (array_keys($options) as $name) {
            if (!in_array($name, self::KNOWN, true)) {
                $errors[] = 'Option inconnue pour la stratégie secondaire : « ' . $name . ' ». Options reconnues : ' . implode(', ', self::KNOWN) . '.';
            }
        }
        $weights = $options[self::OPTION_PERIOD_WEIGHTS] ?? [];
        if (!is_array($weights)) {
            $errors[] = 'Les poids de période sont une table « identifiant de période => poids ».';
        } else {
            foreach ($weights as $period => $weight) {
                if (!is_string($period) || !self::isNumber($weight) || $weight < 0) {
                    $errors[] = 'Poids de période invalide pour « ' . (string) $period . ' » : un nombre positif ou nul est attendu.';
                }
            }
        }
        foreach ([self::OPTION_ROUND_EACH_STEP, WorkProposals::OPTION_BLOCKED] as $flag) {
            if (array_key_exists($flag, $options) && !is_bool($options[$flag])) {
                $errors[] = '« ' . $flag . ' » vaut vrai ou faux.';
            }
        }
        $modes = $options[self::OPTION_MODES] ?? [];
        if (!is_array($modes) || !array_is_list($modes) || array_diff($modes, [self::MODE_SUBJECTS, self::MODE_PERIODS]) !== []) {
            $errors[] = '« aggregation_modes » est une liste de « subjects » ou « periods » (un mode par niveau d\'agrégat).';
        }
        if (array_key_exists(self::OPTION_SUBJECT_ALERT, $options) && $options[self::OPTION_SUBJECT_ALERT] !== null && !self::isNumber($options[self::OPTION_SUBJECT_ALERT])) {
            $errors[] = '« subject_alert_below » est un nombre.';
        }
        if (array_key_exists(WorkProposals::OPTION, $options)) {
            $errors = [...$errors, ...WorkProposals::errors($options[WorkProposals::OPTION])];
        }
        if (array_key_exists(PracticalSubjects::OPTION, $options)) {
            $errors = [...$errors, ...PracticalSubjects::errors($options[PracticalSubjects::OPTION])];
        }
        if (array_key_exists(PromotionPolicy::OPTION, $options)) {
            $errors = [...$errors, ...PromotionPolicy::errors($options[PromotionPolicy::OPTION])];
        }

        return $errors;
    }

    public function computeLearner(CalculationRules $rules, array $units, string $session): array
    {
        $practical = PracticalSubjects::fromOptions($rules->options);
        $averages = [];
        $details = [];
        foreach ($units as $unit) {
            $raw = $this->unitAverage($rules, $unit['groups']);
            $average = $raw === null ? null : $rules->round($raw);
            $averages[$unit['subject_id']] = $average;
            $details[$unit['subject_id']] = [
                'average' => $average, 'raw' => $raw, 'coefficient' => $unit['coefficient'], 'kind' => $unit['kind'], 'evaluated' => $raw !== null,
                'practical' => $practical->covers($unit['kind'], $unit['code']), 'depth' => 0,
            ];
        }
        $overall = $this->general($rules, $details);
        $extras = $this->extras($rules, $details, $overall, 0);
        $proposal = WorkProposals::forAverage($rules->options, $overall);

        return [
            'average' => $overall,
            'subjects' => $averages,
            'credits_earned' => 0,
            'credits_total' => 0,
            'mention' => $overall === null ? null : $rules->mention($overall),
            'deliberation_proposal' => $proposal === null ? '' : $proposal['label'],
            'units' => $details,
            'extras' => $extras,
        ];
    }

    public function aggregatePeriods(CalculationRules $rules, array $periods): array
    {
        $roundEach = $this->roundEachStep($rules);
        $childDepth = 0;
        $sums = [];
        $meta = [];
        $generalSum = 0.0;
        $generalWeights = 0.0;
        foreach ($periods as $period) {
            foreach ($period['units'] as $unitId => $unit) {
                $unitId = (string) $unitId;
                $childDepth = max($childDepth, is_int($unit['depth'] ?? null) ? $unit['depth'] : 0);
                $meta[$unitId] = [
                    'coefficient' => self::isNumber($unit['coefficient'] ?? null) ? (float) $unit['coefficient'] : ($meta[$unitId]['coefficient'] ?? 0.0),
                    'kind' => is_string($unit['kind'] ?? null) ? $unit['kind'] : ($meta[$unitId]['kind'] ?? null),
                    'practical' => ($unit['practical'] ?? false) === true || ($meta[$unitId]['practical'] ?? false),
                ];
            }
            $weight = $this->periodWeight($rules, $period['period_id']);
            if ($weight <= 0.0) {
                continue;
            }
            if ($period['average'] !== null) {
                $generalSum += $period['average'] * $weight;
                $generalWeights += $weight;
            }
            foreach ($period['units'] as $unitId => $unit) {
                $value = $roundEach ? ($unit['average'] ?? null) : ($unit['raw'] ?? $unit['average'] ?? null);
                if (self::isNumber($value)) {
                    $sums[(string) $unitId]['sum'] = ($sums[(string) $unitId]['sum'] ?? 0.0) + (float) $value * $weight;
                    $sums[(string) $unitId]['weights'] = ($sums[(string) $unitId]['weights'] ?? 0.0) + $weight;
                }
            }
        }
        $depth = $childDepth + 1;
        $units = [];
        foreach ($meta as $unitId => $info) {
            $raw = isset($sums[$unitId]) ? $sums[$unitId]['sum'] / $sums[$unitId]['weights'] : null;
            $units[$unitId] = [
                'average' => $raw === null ? null : $rules->round($raw), 'raw' => $raw, 'coefficient' => $info['coefficient'], 'kind' => $info['kind'],
                'evaluated' => $raw !== null, 'practical' => $info['practical'], 'depth' => $depth,
            ];
        }
        if ($this->modeFor($rules, $depth) === self::MODE_PERIODS) {
            $average = $generalWeights > 0.0 ? $rules->round($generalSum / $generalWeights) : null;
        } else {
            $average = $this->general($rules, $units);
        }

        return [
            'average' => $average,
            'credits_earned' => 0,
            'credits_total' => 0,
            'units' => $units,
            'extras' => $this->extras($rules, $units, $average, $depth),
        ];
    }

    public function decideYear(CalculationRules $rules, array $periods, array $context): array
    {
        $aggregate = $this->aggregatePeriods($rules, $periods);
        $promotion = PromotionPolicy::propose($rules->options, $aggregate['average'], $this->lowestPractical($aggregate['units']), $context);
        $extras = ($aggregate['extras'] ?? []) + ['promotion' => null];
        $extras['promotion'] = $promotion === null ? null : $promotion + ['label' => $rules->label($promotion['code'])];

        return [
            'average' => $aggregate['average'],
            'credits_earned' => 0,
            'credits_total' => 0,
            'decision' => $promotion === null ? '' : $rules->label($promotion['code']),
            'extras' => $extras,
        ];
    }

    /**
     * Moyenne générale pondérée par les coefficients des matières évaluées.
     *
     * @param array<string, array<string, mixed>> $units
     */
    private function general(CalculationRules $rules, array $units): ?float
    {
        $roundEach = $this->roundEachStep($rules);
        $sum = 0.0;
        $weights = 0.0;
        foreach ($units as $unit) {
            $value = $roundEach ? $unit['average'] : $unit['raw'];
            $factor = (float) $unit['coefficient'];
            if (self::isNumber($value) && $factor > 0.0) {
                $sum += (float) $value * $factor;
                $weights += $factor;
            }
        }

        return $weights > 0.0 ? $rules->round($sum / $weights) : null;
    }

    /**
     * @param array<string, array<string, mixed>> $units
     * @return array<string, mixed>
     */
    private function extras(CalculationRules $rules, array $units, ?float $average, int $depth): array
    {
        $alertBelow = $rules->options[self::OPTION_SUBJECT_ALERT] ?? null;
        $practical = PracticalSubjects::fromOptions($rules->options);
        $notEvaluated = [];
        $alerts = [];
        $practicalAlerts = [];
        foreach ($units as $unitId => $unit) {
            $value = $unit['average'];
            if (!self::isNumber($value)) {
                $notEvaluated[] = (string) $unitId;

                continue;
            }
            if (self::isNumber($alertBelow) && $value < $alertBelow) {
                $alerts[] = (string) $unitId;
            }
            if ($unit['practical'] === true && $practical->isBelow((float) $value)) {
                $practicalAlerts[] = (string) $unitId;
            }
        }
        $proposal = WorkProposals::forAverage($rules->options, $average);
        $extras = [
            'depth' => $depth,
            'not_evaluated' => $notEvaluated,
            'subject_alerts' => $alerts,
            'practical_alerts' => $practicalAlerts,
            'work_proposal' => $proposal,
        ];
        if (PromotionPolicy::depth($rules->options) === $depth) {
            $promotion = PromotionPolicy::propose($rules->options, $average, $this->lowestPractical($units));
            $extras['promotion'] = $promotion === null ? null : $promotion + ['label' => $rules->label($promotion['code'])];
        }

        return $extras;
    }

    /** @param array<string, array<string, mixed>> $units */
    private function lowestPractical(array $units): ?float
    {
        $lowest = null;
        foreach ($units as $unit) {
            $value = $unit['average'] ?? null;
            if (($unit['practical'] ?? false) === true && self::isNumber($value)) {
                $lowest = $lowest === null ? (float) $value : min($lowest, (float) $value);
            }
        }

        return $lowest;
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
        if (!self::isNumber($value) || $max <= 0.0) {
            return null;
        }

        return (float) $value / $max * $rules->scale;
    }

    private function roundEachStep(CalculationRules $rules): bool
    {
        return ($rules->options[self::OPTION_ROUND_EACH_STEP] ?? true) !== false;
    }

    private function modeFor(CalculationRules $rules, int $depth): string
    {
        $modes = $rules->options[self::OPTION_MODES] ?? [];
        if (!is_array($modes) || $modes === []) {
            return self::MODE_SUBJECTS;
        }
        $modes = array_values($modes);
        $mode = $modes[min($depth, count($modes)) - 1];

        return $mode === self::MODE_PERIODS ? self::MODE_PERIODS : self::MODE_SUBJECTS;
    }

    private function periodWeight(CalculationRules $rules, string $periodId): float
    {
        $weights = $rules->options[self::OPTION_PERIOD_WEIGHTS] ?? [];
        $weight = is_array($weights) ? ($weights[$periodId] ?? null) : null;

        return self::isNumber($weight) ? (float) $weight : 1.0;
    }

    private static function isNumber(mixed $value): bool
    {
        return is_int($value) || is_float($value);
    }
}
