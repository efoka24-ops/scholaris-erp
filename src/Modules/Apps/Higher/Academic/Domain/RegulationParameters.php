<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Academic\Domain;

/**
 * Paramètres d'un règlement des études (EX-ACA-05) : formules, seuils, compensation, rattrapage, passage. Valeurs par défaut du
 * CDC (« Règles de calcul ») remplaçables par le règlement de la tutelle ; liste blanche de clés, chaque valeur contrôlée.
 * Le moteur de calcul (Assessment, S5-S7) et la délibération lisent ces valeurs ; Academic ne calcule rien.
 */
final class RegulationParameters
{
    /** @var array<string, array{0: string, 1: float|int|null, 2: float|int|null}> clé => [type, minimum, maximum] */
    private const RULES = [
        // F1 : note d'EC = poids CC × CC + (100 - poids) × examen ; arrondi au centième à chaque étape ; saisie au quart de point.
        'cc_weight_percent' => ['int', 0, 100],
        'rounding_decimals' => ['int', 0, 3],
        'grade_step' => ['number', 0.01, 1],
        // Validation d'UE, compensation, note éliminatoire d'UE (par UE, pas par EC).
        'unit_pass_mark' => ['number', 0, 20],
        'compensation_enabled' => ['bool', null, null],
        'compensation_threshold' => ['number', 0, 20],
        'eliminatory_unit_mark' => ['number', 0, 20],
        // Rattrapage : remplace l'examen, CC conservé ; plafond facultatif de la moyenne d'UE.
        'resit_enabled' => ['bool', null, null],
        'resit_replaces_exam' => ['bool', null, null],
        'resit_unit_cap' => ['nullable_number', 0, 20],
        // Passage : 60 crédits admis, 45 à 59 admis avec dettes, moins de 45 redouble.
        'progression_admitted_credits' => ['int', 1, 240],
        'progression_debts_min_credits' => ['int', 0, 240],
        'credits_per_semester' => ['int', 1, 60],
        'jury_points_max' => ['number', 0, 2],
        'max_consecutive_enrolments' => ['int', 1, 5],
        // BTS / HND : moyenne annuelle de passage, assiduité pour l'examen national.
        'annual_pass_average' => ['number', 0, 20],
        'national_exam_min_attendance_percent' => ['int', 0, 100],
    ];

    private const COMMON = [
        'cc_weight_percent' => 40, 'rounding_decimals' => 2, 'grade_step' => 0.25, 'jury_points_max' => 0.5,
        'max_consecutive_enrolments' => 2, 'resit_enabled' => true, 'resit_replaces_exam' => true, 'resit_unit_cap' => null,
    ];

    private const CREDIT_BASED = [
        'unit_pass_mark' => 10, 'compensation_enabled' => true, 'compensation_threshold' => 10, 'eliminatory_unit_mark' => 7,
        'progression_admitted_credits' => 60, 'progression_debts_min_credits' => 45, 'credits_per_semester' => 30,
    ];

    private const NATIONAL_EXAM = ['annual_pass_average' => 10, 'national_exam_min_attendance_percent' => 70];

    private function __construct()
    {
    }

    /** @return array<string, bool|float|int|null> */
    public static function defaults(Regime $regime): array
    {
        return $regime->isNationalExam() ? self::COMMON + self::NATIONAL_EXAM : self::COMMON + self::CREDIT_BASED;
    }

    /**
     * Valeurs de base complétées par les surcharges, après contrôle. Clé inconnue ou valeur hors bornes : erreurs par champ.
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $overrides
     * @return array{0: array<string, bool|float|int|null>, 1: array<string, list<string>>}
     */
    public static function merge(array $base, array $overrides): array
    {
        $values = [];
        foreach ($base as $key => $value) {
            if (isset(self::RULES[$key]) && (is_bool($value) || is_int($value) || is_float($value) || $value === null)) {
                $values[$key] = $value;
            }
        }
        $errors = [];
        foreach ($overrides as $key => $value) {
            $key = (string) $key;
            if (!isset(self::RULES[$key])) {
                $errors['parameters.' . $key] = ['Paramètre inconnu du règlement des études.'];
                continue;
            }
            [$type, $min, $max] = self::RULES[$key];
            $parsed = self::parse($type, $value, $min, $max);
            if ($parsed instanceof \InvalidArgumentException) {
                $errors['parameters.' . $key] = [$parsed->getMessage()];
                continue;
            }
            $values[$key] = $parsed;
        }
        if (isset($values['progression_admitted_credits'], $values['progression_debts_min_credits'])
            && $values['progression_debts_min_credits'] > $values['progression_admitted_credits']) {
            $errors['parameters.progression_debts_min_credits'] = ['Le minimum « admis avec dettes » ne peut pas dépasser le seuil « admis ».'];
        }
        ksort($values);

        return [$values, $errors];
    }

    private static function parse(string $type, mixed $value, float|int|null $min, float|int|null $max): bool|float|int|null|\InvalidArgumentException
    {
        if ($type === 'bool') {
            return is_bool($value) ? $value : new \InvalidArgumentException('Valeur booléenne attendue.');
        }
        if ($type === 'nullable_number' && $value === null) {
            return null;
        }
        $numeric = $type === 'int' ? is_int($value) : is_int($value) || is_float($value);
        if (!$numeric) {
            return new \InvalidArgumentException($type === 'int' ? 'Nombre entier attendu.' : 'Nombre attendu.');
        }
        if (($min !== null && $value < $min) || ($max !== null && $value > $max)) {
            return new \InvalidArgumentException(sprintf('Valeur attendue entre %s et %s.', (string) $min, (string) $max));
        }

        return $type === 'int' ? (int) $value : (float) $value;
    }
}
