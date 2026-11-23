<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Contracts;

/**
 * Règles de calcul en vigueur, vue publique (S5, S6) : ce qu'une stratégie de calcul reçoit. Les options sont propres à
 * la stratégie (poids CC/examen, note éliminatoire d'UE, grille de lettres...), les étiquettes de décision sont
 * paramétrables (S9) et les effets des codes d'absence déclaratifs (S1).
 */
final class CalculationRules
{
    public const EFFECT_ZERO = 'zero';
    public const EFFECT_EXCLUDED = 'excluded';
    public const EFFECT_RETAKE = 'retake';

    /**
     * @param list<array{min: float, label: string}> $mentions de la plus haute à la plus basse
     * @param array<string, mixed> $options options de la stratégie
     * @param array<string, string> $decisionLabels code de décision => libellé (admitted, compensated, resit, adjourned...)
     * @param list<string> $passingDecisions codes des décisions qui valent admission
     * @param array<string, string> $absenceEffects code d'absence => zero | excluded | retake
     */
    public function __construct(
        public readonly int $version,
        public readonly string $strategy,
        public readonly float $scale,
        public readonly float $passMark,
        public readonly int $rounding,
        public readonly array $mentions,
        public readonly float $compensationFrom,
        public readonly float $capitalizeFrom,
        public readonly float $resitFrom,
        public readonly array $options,
        public readonly array $decisionLabels,
        public readonly array $passingDecisions,
        public readonly array $absenceEffects,
    ) {
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }

    public function number(string $key, float $default): float
    {
        $value = $this->options[$key] ?? null;

        return is_int($value) || is_float($value) ? (float) $value : $default;
    }

    /** Libellé d'une décision ; le code lui-même s'il n'est pas déclaré. */
    public function label(string $decision): string
    {
        return $this->decisionLabels[$decision] ?? $decision;
    }

    public function isPassing(string $label): bool
    {
        foreach ($this->passingDecisions as $code) {
            if ($this->label($code) === $label) {
                return true;
            }
        }

        return false;
    }

    /** Arrondi de l'établissement (au centième par défaut, demi supérieur). */
    public function round(float $value): float
    {
        return round($value, $this->rounding, PHP_ROUND_HALF_UP);
    }

    public function mention(float $average): ?string
    {
        foreach ($this->mentions as $mention) {
            if ($average >= $mention['min']) {
                return $mention['label'];
            }
        }

        return null;
    }
}
