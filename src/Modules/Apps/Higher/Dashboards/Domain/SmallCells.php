<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Dashboards\Domain;

/**
 * Protection des petits effectifs (aucune donnée nominative ni déductible) : une ligne dont un effectif de contrôle est
 * strictement positif et inférieur au seuil (5 par défaut) est masquée (valeurs nulles, `suppressed` vrai). Suppression
 * complémentaire : quand une seule ligne est masquée, la plus petite autre ligne non nulle l'est aussi, pour que le total
 * affiché ne permette pas de retrouver la ligne masquée. Politique propre au tableau de bord (le socle ne fournit pas encore
 * d'implémentation de DisclosurePolicy) ; le seuil est un paramètre de l'appelant.
 */
final class SmallCells
{
    public const MINIMUM = 5;

    private function __construct()
    {
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string> $guards colonnes d'effectif qui déclenchent le masquage
     * @param list<string> $hidden colonnes rendues nulles dans une ligne masquée
     * @return list<array<string, mixed>>
     */
    public static function apply(array $rows, array $guards, array $hidden, int $minimum = self::MINIMUM): array
    {
        $masked = [];
        foreach ($rows as $index => $row) {
            foreach ($guards as $guard) {
                $value = (int) ($row[$guard] ?? 0);
                if ($value > 0 && $value < $minimum) {
                    $masked[$index] = true;
                    break;
                }
            }
        }
        if (count($masked) === 1) {
            $best = null;
            $bestSize = PHP_INT_MAX;
            foreach ($rows as $index => $row) {
                $size = 0;
                foreach ($guards as $guard) {
                    $size += (int) ($row[$guard] ?? 0);
                }
                if (!isset($masked[$index]) && $size > 0 && $size < $bestSize) {
                    $best = $index;
                    $bestSize = $size;
                }
            }
            if ($best !== null) {
                $masked[$best] = true;
            }
        }
        foreach (array_keys($masked) as $index) {
            foreach ($hidden as $key) {
                $rows[$index][$key] = null;
            }
            $rows[$index]['suppressed'] = true;
        }

        return array_map(static fn (array $row): array => $row + ['suppressed' => false], $rows);
    }

    /** Effectif d'une carte : masqué (null) s'il est strictement positif et inférieur au seuil. */
    public static function card(int $count, int $minimum = self::MINIMUM): ?int
    {
        return $count > 0 && $count < $minimum ? null : $count;
    }
}
