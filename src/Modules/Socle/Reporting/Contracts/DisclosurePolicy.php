<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Reporting\Contracts;

/**
 * Politique configurable de protection des cellules statistiques.
 *
 * Les seuils sont fournis par l'appelant à chaque calcul : aucune valeur par défaut ni politique officielle n'est fixée ici.
 */
interface DisclosurePolicy
{
    public function suppressPrimary(int $count, int $minimumCellSize): bool;

    /**
     * Choisit les cellules supplémentaires à supprimer pour réduire les déductions à partir des cellules visibles.
     *
     * @param array<string, int> $cellCounts
     * @param list<string> $primarySuppressedCellIds
     * @return list<string>
     */
    public function suppressSecondary(array $cellCounts, array $primarySuppressedCellIds, int $minimumCellSize): array;
}
