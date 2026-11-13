<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Workshops\Contracts;

/**
 * Évaluation pratique paramétrable (T909, T943), contrat exposé par Workshops : la somme des points observés est ramenée à la
 * base de la fiche, puis plafonnée si l'enseignant coche un manquement grave et que la fiche porte un plafond. Critères, base et
 * plafond viennent tous de la fiche de l'établissement : aucune valeur officielle n'est connue ici.
 */
interface PracticalGrid
{
    /**
     * @param list<array{code: string, label: string, points: float|int}> $criteria
     * @param array<string, float|int> $observed
     * @return array{score: float, uncapped: float, capped: bool, observed: float, total: float}
     *
     * @throws \InvalidArgumentException grille ou points observés invalides
     */
    public function score(array $criteria, float $base, ?float $safetyCap, array $observed, bool $seriousSafetyBreach): array;
}
