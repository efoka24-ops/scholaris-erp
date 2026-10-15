<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Deliberation\Domain;

use Scholaris\Modules\Socle\Assessment\Contracts\CalculationRules;

/**
 * Lecture tolérante des options des règles de calcul de l'IPES. Les clés sont celles du règlement des études (Academic,
 * RegulationParameters : cc_weight_percent, eliminatory_unit_mark…) ; quelques alias courts sont acceptés (cc_weight, eliminatory).
 */
final class Rules
{
    /** Mentions par défaut du CDC (si les règles n'en déclarent aucune). */
    public const MENTIONS = [
        ['min' => 16.0, 'label' => 'Très bien'], ['min' => 14.0, 'label' => 'Bien'],
        ['min' => 12.0, 'label' => 'Assez bien'], ['min' => 10.0, 'label' => 'Passable'],
    ];

    public function __construct(public readonly CalculationRules $rules)
    {
    }

    /** @param list<string> $keys */
    public function number(array $keys, float $default): float
    {
        foreach ($keys as $key) {
            $value = $this->rules->options[$key] ?? null;
            if (is_int($value) || is_float($value)) {
                return (float) $value;
            }
        }

        return $default;
    }

    public function flag(string $key, bool $default): bool
    {
        $value = $this->rules->options[$key] ?? null;

        return is_bool($value) ? $value : $default;
    }

    public function nullableNumber(string $key): ?float
    {
        $value = $this->rules->options[$key] ?? null;

        return is_int($value) || is_float($value) ? (float) $value : null;
    }

    /** @return array<string, float> clé (code ou identifiant) => valeur */
    public function map(string $key): array
    {
        $raw = $this->rules->options[$key] ?? null;
        $out = [];
        if (is_array($raw)) {
            foreach ($raw as $k => $v) {
                if (is_int($v) || is_float($v)) {
                    $out[(string) $k] = (float) $v;
                }
            }
        }

        return $out;
    }

    /**
     * @param list<string> $default
     * @return list<string>
     */
    public function kinds(string $key, array $default): array
    {
        $raw = $this->rules->options[$key] ?? null;

        return is_array($raw) && $raw !== [] ? array_values(array_map('strval', $raw)) : $default;
    }

    public function round(float $value): float
    {
        return $this->rules->round($value);
    }

    public function scale(): float
    {
        return $this->rules->scale > 0 ? $this->rules->scale : 20.0;
    }

    /** Poids du contrôle continu (0 à 1) : par EC (element_cc_weights, en %), sinon celui de la filière. */
    public function ccWeight(?string ...$elementKeys): float
    {
        $perElement = $this->map('element_cc_weights');
        foreach ($elementKeys as $key) {
            if ($key !== null && isset($perElement[$key])) {
                return max(0.0, min(1.0, $perElement[$key] / 100));
            }
        }
        $percent = $this->nullableNumber('cc_weight_percent');

        return $percent !== null ? max(0.0, min(1.0, $percent / 100)) : max(0.0, min(1.0, $this->number(['cc_weight'], 0.4)));
    }

    /** Note éliminatoire d'UE : par UE (unit_eliminatory), sinon celle du règlement (7 par défaut). */
    public function eliminatory(?string ...$unitKeys): float
    {
        $perUnit = $this->map('unit_eliminatory');
        foreach ($unitKeys as $key) {
            if ($key !== null && isset($perUnit[$key])) {
                return $perUnit[$key];
            }
        }

        return $this->number(['eliminatory_unit_mark', 'eliminatory'], 7.0);
    }

    public function mention(float $average): ?string
    {
        $mentions = $this->rules->mentions !== [] ? $this->rules->mentions : self::MENTIONS;
        foreach ($mentions as $mention) {
            if ($average >= $mention['min']) {
                return $mention['label'];
            }
        }

        return null;
    }

    /** @return list<float> seuils de mention, du plus haut au plus bas */
    public function mentionThresholds(): array
    {
        $mentions = $this->rules->mentions !== [] ? $this->rules->mentions : self::MENTIONS;

        return array_map(static fn (array $m): float => (float) $m['min'], $mentions);
    }
}
