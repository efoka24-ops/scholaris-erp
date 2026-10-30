<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Reporting\Application;

use Scholaris\Modules\Socle\Reporting\Contracts\DisclosurePolicy;

/**
 * Politique de seuil (PP4, TST-10) : une case dont l'effectif est strictement positif et inférieur au seuil est masquée
 * (un zéro reste publié : il ne désigne personne). Masquage secondaire : tant qu'une seule case est masquée, ou que la somme
 * des cases masquées reste sous le seuil, la plus petite case voisine visible (non nulle) est masquée à son tour, pour qu'aucune
 * case masquée ne se déduise du total. Le seuil est fourni par l'appelant (paramètre `reporting.disclosure.minimum_cell_size`).
 */
final class ThresholdDisclosurePolicy implements DisclosurePolicy
{
    public function suppressPrimary(int $count, int $minimumCellSize): bool
    {
        return $count > 0 && $count < $minimumCellSize;
    }

    public function suppressSecondary(array $cellCounts, array $primarySuppressedCellIds, int $minimumCellSize): array
    {
        $suppressed = array_values(array_intersect($primarySuppressedCellIds, array_keys($cellCounts)));
        if ($suppressed === []) {
            return [];
        }
        $visible = array_filter(
            $cellCounts,
            static fn (int $count, string|int $cell): bool => $count > 0 && !in_array((string) $cell, $suppressed, true),
            ARRAY_FILTER_USE_BOTH,
        );
        uksort($visible, static fn (string|int $a, string|int $b): int => [$visible[$a], (string) $a] <=> [$visible[$b], (string) $b]);

        $secondary = [];
        $sum = array_sum(array_map(static fn (string $cell): int => $cellCounts[$cell], $suppressed));
        foreach ($visible as $cell => $count) {
            if (count($suppressed) + count($secondary) > 1 && $sum >= $minimumCellSize) {
                break;
            }
            $secondary[] = (string) $cell;
            $sum += $count;
        }

        return $secondary;
    }
}
