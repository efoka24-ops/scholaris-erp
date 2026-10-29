<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Reporting\Contracts;

/**
 * Lecture des agrégats d'un territoire (PP4) pour les applications (tableau de bord territorial DOC-28 des écoles publiques) :
 * mêmes valeurs que GET /dashboards/territories/{territory}, déjà masquées (une case masquée vaut null, `suppressed` vrai).
 * L'autorisation de l'appelant sur le territoire reste à sa charge.
 */
interface TerritoryAggregates
{
    /**
     * @return array{territory_id: string, reference_date: string|null, minimum_cell_size: int, indicators: list<array{key: string, establishment_count: int, computed_at: string, cells: list<array{key: string, value: int|null, suppressed: bool}>}>}
     */
    public function read(string $tenantId, string $territoryId, ?string $referenceDate = null): array;
}
