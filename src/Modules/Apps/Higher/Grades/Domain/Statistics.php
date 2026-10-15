<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Grades\Domain;

/**
 * Statistiques d'une série de notes ramenées sur 20 (EX-NOT-09) : moyenne, écart-type (population), minimum, maximum, taux de
 * réussite (note ≥ seuil) et distribution par tranches. Arrondi au centième.
 */
final class Statistics
{
    /** Bornes basses des tranches de la distribution (sur 20). */
    public const BUCKETS = [0, 4, 8, 10, 12, 14, 16];

    private function __construct()
    {
    }

    /**
     * @param list<float> $scores notes sur 20
     * @return array{count: int, mean: ?float, std_dev: ?float, min: ?float, max: ?float, pass_rate: ?float, distribution: list<array{from: int, to: int, count: int}>}
     */
    public static function of(array $scores, float $passMark = 10.0): array
    {
        $count = count($scores);
        $distribution = [];
        foreach (self::BUCKETS as $index => $from) {
            $to = self::BUCKETS[$index + 1] ?? 20;
            $distribution[] = ['from' => $from, 'to' => $to, 'count' => count(array_filter(
                $scores,
                static fn (float $s): bool => $s >= $from && ($to === 20 ? $s <= 20 : $s < $to),
            ))];
        }
        if ($count === 0) {
            return ['count' => 0, 'mean' => null, 'std_dev' => null, 'min' => null, 'max' => null, 'pass_rate' => null, 'distribution' => $distribution];
        }
        $mean = array_sum($scores) / $count;
        $variance = array_sum(array_map(static fn (float $s): float => ($s - $mean) ** 2, $scores)) / $count;

        return [
            'count' => $count,
            'mean' => round($mean, 2),
            'std_dev' => round(sqrt($variance), 2),
            'min' => round(min($scores), 2),
            'max' => round(max($scores), 2),
            'pass_rate' => round(100 * count(array_filter($scores, static fn (float $s): bool => $s >= $passMark - 1e-9)) / $count, 2),
            'distribution' => $distribution,
        ];
    }
}
