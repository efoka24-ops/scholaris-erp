<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Councils\Domain;

/**
 * Proposition automatique de passage en fin d'année (EX-PAS-01), à partir de l'option `promotion` de la version de règles :
 * `admit_from` (admis à partir de), `review_from` (cas examiné par le conseil à partir de, sinon redoublement),
 * `exclude_repeaters` (redoublant déjà dans le niveau ou âge au-delà de la limite : exclusion pour insuffisance proposée), et de
 * l'option `practical` des établissements techniques : moyenne de pratique professionnelle sous `min` → admission refusée
 * quelle que soit la moyenne générale, passage conditionnel possible si `conditional_allowed`. Sans `admit_from`, aucune
 * proposition : la décision reste au conseil. Toujours une PROPOSITION : le conseil décide (RG-BUL-2). Fonctions pures.
 */
final class PromotionPolicy
{
    public const OPTION = 'promotion';
    public const ADMITTED = 'admitted';
    public const COUNCIL_REVIEW = 'council_review';
    public const REPEAT = 'repeat';
    public const EXCLUSION = 'exclusion_proposed';
    public const PRACTICAL_REFUSED = 'practical_refused';

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $options options de la stratégie
     * @param float|null $practicalAverage plus basse moyenne annuelle des matières de pratique (null : aucune)
     * @param array<string, mixed> $context is_repeater, over_age_limit (données de l'application)
     * @return array{code: string, conditional_possible: bool, practical_below: bool}|null
     */
    public static function propose(array $options, ?float $average, ?float $practicalAverage, array $context = []): ?array
    {
        $promotion = $options[self::OPTION] ?? null;
        if ($average === null || !is_array($promotion) || !self::isNumber($promotion['admit_from'] ?? null)) {
            return null;
        }
        $admitFrom = (float) $promotion['admit_from'];
        $reviewFrom = self::isNumber($promotion['review_from'] ?? null) ? (float) $promotion['review_from'] : $admitFrom;
        if ($average >= $admitFrom) {
            $code = self::ADMITTED;
        } elseif ($average >= $reviewFrom) {
            $code = self::COUNCIL_REVIEW;
        } else {
            $code = self::REPEAT;
            $flagged = ($context['is_repeater'] ?? false) === true || ($context['over_age_limit'] ?? false) === true;
            if ($flagged && ($promotion['exclude_repeaters'] ?? false) === true) {
                $code = self::EXCLUSION;
            }
        }
        $practical = PracticalSubjects::fromOptions($options);
        $below = $practicalAverage !== null && $practical->min !== null && $practicalAverage < $practical->min;
        if ($below && in_array($code, [self::ADMITTED, self::COUNCIL_REVIEW], true)) {
            $code = self::PRACTICAL_REFUSED;
        }

        return ['code' => $code, 'conditional_possible' => $code === self::PRACTICAL_REFUSED && $practical->conditionalAllowed, 'practical_below' => $below];
    }

    /** @return list<string> */
    public static function errors(mixed $promotion): array
    {
        if (!is_array($promotion)) {
            return ['« promotion » est un objet {at_depth, admit_from, review_from, exclude_repeaters}.'];
        }
        $errors = [];
        foreach (['admit_from', 'review_from'] as $key) {
            if (array_key_exists($key, $promotion) && !self::isNumber($promotion[$key])) {
                $errors[] = '« promotion.' . $key . ' » est un nombre.';
            }
        }
        if (self::isNumber($promotion['admit_from'] ?? null) && self::isNumber($promotion['review_from'] ?? null) && $promotion['review_from'] > $promotion['admit_from']) {
            $errors[] = '« promotion.review_from » ne dépasse pas « promotion.admit_from ».';
        }
        if (array_key_exists('at_depth', $promotion) && (!is_int($promotion['at_depth']) || $promotion['at_depth'] < 1)) {
            $errors[] = '« promotion.at_depth » est un entier positif (niveau d\'agrégat : 1 = trimestre, 2 = année dans le découpage habituel).';
        }
        if (array_key_exists('exclude_repeaters', $promotion) && !is_bool($promotion['exclude_repeaters'])) {
            $errors[] = '« promotion.exclude_repeaters » vaut vrai ou faux.';
        }

        return $errors;
    }

    /** @param array<string, mixed> $options */
    public static function depth(array $options): ?int
    {
        $promotion = $options[self::OPTION] ?? null;

        return is_array($promotion) && is_int($promotion['at_depth'] ?? null) ? $promotion['at_depth'] : null;
    }

    private static function isNumber(mixed $value): bool
    {
        return is_int($value) || is_float($value);
    }
}
