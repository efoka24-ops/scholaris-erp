<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Domain;

use InvalidArgumentException;

/** Calculs sur des montants entiers en XAF : jamais de décimales, aucun centime perdu. */
final class Amounts
{
    /** Montant d'une remise : pourcentage (arrondi à l'entier inférieur) ou montant fixe, plafonné à la base. */
    public static function discount(string $mode, int $value, int $base): int
    {
        if ($value < 0 || $base < 0) {
            throw new InvalidArgumentException('Remise et base doivent être positives.');
        }
        if ($mode === 'percent') {
            if ($value > 100) {
                throw new InvalidArgumentException('Un pourcentage ne peut pas dépasser 100.');
            }

            return intdiv($base * $value, 100);
        }

        return min($value, $base);
    }

    /**
     * Répartit une remise sur les montants restants des tranches, au prorata, le reliquat revenant à la dernière.
     *
     * @param list<int> $outstanding
     * @return list<int> remise par tranche (jamais supérieure au restant)
     */
    public static function spread(int $discount, array $outstanding): array
    {
        $total = array_sum($outstanding);
        if ($total <= 0 || $discount <= 0) {
            return array_fill(0, count($outstanding), 0);
        }
        $discount = min($discount, $total);

        $shares = [];
        $given = 0;
        foreach ($outstanding as $out) {
            $share = intdiv($discount * $out, $total);
            $shares[] = $share;
            $given += $share;
        }
        for ($i = count($shares) - 1; $i >= 0 && $given < $discount; $i--) {
            $room = $outstanding[$i] - $shares[$i];
            $extra = min($room, $discount - $given);
            $shares[$i] += $extra;
            $given += $extra;
        }

        return array_values($shares);
    }

    /**
     * Découpe un total en tranches égales ; le reliquat est réparti sur les premières.
     *
     * @return list<int>
     */
    public static function split(int $total, int $parts): array
    {
        if ($parts < 1 || $total < 0) {
            throw new InvalidArgumentException('Découpage impossible.');
        }
        $base = intdiv($total, $parts);
        $rest = $total - $base * $parts;

        return array_map(static fn (int $i): int => $base + ($i < $rest ? 1 : 0), range(0, $parts - 1));
    }
}
