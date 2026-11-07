<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Deliberation\Application\Strategies;

use Scholaris\Modules\Apps\Higher\Deliberation\Domain\LetterGrid;

/** Contrôle des options des règles de l'IPES à la publication d'une version (RuleStrategy::optionErrors). */
final class OptionChecks
{
    private const NUMBERS = [
        'cc_weight_percent' => [0, 100], 'cc_weight' => [0, 1], 'eliminatory_unit_mark' => [0, 20], 'eliminatory' => [0, 20],
        'unit_pass_mark' => [0, 20], 'unit_pass' => [0, 20], 'compensation_threshold' => [0, 20], 'semester_pass' => [0, 20],
        'jury_points_max' => [0, 2], 'progression_admitted_credits' => [1, 240], 'progression_debts_min_credits' => [0, 240],
        'max_consecutive_enrolments' => [1, 5], 'final_credits' => [1, 600], 'annual_pass_average' => [0, 20], 'credits_per_semester' => [1, 60],
    ];

    /**
     * @param array<string, mixed> $options
     * @return list<string>
     */
    public static function errors(array $options): array
    {
        $errors = [];
        foreach (self::NUMBERS as $key => [$min, $max]) {
            if (!array_key_exists($key, $options)) {
                continue;
            }
            $value = $options[$key];
            if (!is_int($value) && !is_float($value)) {
                $errors[] = $key . ' doit être un nombre.';
            } elseif ($value < $min || $value > $max) {
                $errors[] = sprintf('%s doit être compris entre %s et %s.', $key, $min, $max);
            }
        }
        if (array_key_exists('resit_unit_cap', $options) && $options['resit_unit_cap'] !== null
            && (!is_int($options['resit_unit_cap']) && !is_float($options['resit_unit_cap']) || $options['resit_unit_cap'] < 0 || $options['resit_unit_cap'] > 20)) {
            $errors[] = 'resit_unit_cap doit être nul ou compris entre 0 et 20.';
        }
        foreach (['compensation_enabled', 'resit_enabled', 'resit_replaces_exam'] as $key) {
            if (array_key_exists($key, $options) && !is_bool($options[$key])) {
                $errors[] = $key . ' doit être un booléen.';
            }
        }
        foreach (['element_cc_weights' => 100, 'unit_eliminatory' => 20] as $key => $max) {
            if (!array_key_exists($key, $options)) {
                continue;
            }
            if (!is_array($options[$key])) {
                $errors[] = $key . ' doit être un objet code => valeur.';
                continue;
            }
            foreach ($options[$key] as $code => $value) {
                if ((!is_int($value) && !is_float($value)) || $value < 0 || $value > $max) {
                    $errors[] = sprintf('%s.%s doit être compris entre 0 et %d.', $key, $code, $max);
                }
            }
        }
        if (array_key_exists('letter_grid', $options)) {
            array_push($errors, ...LetterGrid::errors($options['letter_grid']));
        }
        if (isset($options['progression_admitted_credits'], $options['progression_debts_min_credits'])
            && $options['progression_debts_min_credits'] > $options['progression_admitted_credits']) {
            $errors[] = 'Le minimum « admis avec dettes » ne peut pas dépasser le seuil « admis ».';
        }

        return $errors;
    }
}
