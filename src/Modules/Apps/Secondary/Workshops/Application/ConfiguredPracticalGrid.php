<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Workshops\Application;

use InvalidArgumentException;
use Scholaris\Modules\Apps\Secondary\Workshops\Contracts\PracticalGrid;

/** Grille pratique : critères, base et plafond sont ceux de la fiche de l'établissement (aucune valeur officielle ici). */
final class ConfiguredPracticalGrid implements PracticalGrid
{
    public function score(array $criteria, float $base, ?float $safetyCap, array $observed, bool $seriousSafetyBreach): array
    {
        if ($criteria === [] || $base <= 0.0 || ($safetyCap !== null && ($safetyCap < 0.0 || $safetyCap > $base))) {
            throw new InvalidArgumentException('Une grille doit contenir des critères, une base positive et un plafond valide.');
        }
        $total = 0.0;
        $sum = 0.0;
        $known = [];
        foreach ($criteria as $criterion) {
            $code = $criterion['code'];
            $points = (float) $criterion['points'];
            if ($points <= 0.0 || isset($known[$code])) {
                throw new InvalidArgumentException('Chaque critère doit avoir un code distinct et un maximum positif.');
            }
            $known[$code] = true;
            $value = (float) ($observed[$code] ?? 0.0);
            if ($value < 0.0 || $value > $points) {
                throw new InvalidArgumentException('Les points observés doivent rester dans les limites de chaque critère.');
            }
            $total += $points;
            $sum += $value;
        }
        foreach (array_keys($observed) as $code) {
            if (!isset($known[$code])) {
                throw new InvalidArgumentException('Un critère observé ne figure pas dans la grille.');
            }
        }
        // Somme observée ramenée à la base de la fiche : 12 points sur un total de 20 donnent 12/20 avec une base de 20.
        $uncapped = round($sum / $total * $base, 2);
        $capped = $seriousSafetyBreach && $safetyCap !== null && $uncapped > $safetyCap;

        return ['score' => $capped ? $safetyCap : $uncapped, 'uncapped' => $uncapped, 'capped' => $capped, 'observed' => $sum, 'total' => $total];
    }
}
