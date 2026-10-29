<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Reporting\Application;

use Scholaris\Modules\Socle\Reporting\Contracts\DisclosurePolicy;
use Scholaris\Modules\Socle\Reporting\Contracts\TerritoryAggregates;
use Scholaris\Modules\Socle\Reporting\Infrastructure\TerritoryAggregateRepository;

/**
 * Lecture des agrégats d'un territoire avec masquage des petits effectifs (PP4, TST-10) : chaque indicateur est traité
 * comme un tableau de cases ; une case masquée est rendue avec `value: null` et `suppressed: true`. Seul ce lecteur expose
 * les agrégats : la valeur brute d'une case masquée ne sort jamais.
 */
final class TerritoryAggregateReader implements TerritoryAggregates
{
    public function __construct(
        private readonly TerritoryAggregateRepository $repository,
        private readonly DisclosurePolicy $policy,
        private readonly int $minimumCellSize,
    ) {
    }

    /**
     * @return array{territory_id: string, reference_date: string|null, minimum_cell_size: int, indicators: list<array{key: string, establishment_count: int, computed_at: string, cells: list<array{key: string, value: int|null, suppressed: bool}>}>}
     */
    public function read(string $tenantId, string $territoryId, ?string $referenceDate = null): array
    {
        $date = $referenceDate ?? $this->repository->latestDate($tenantId, $territoryId);
        $grouped = [];
        foreach ($date === null ? [] : $this->repository->cells($tenantId, $territoryId, $date) as $row) {
            $grouped[$row['indicator_key']]['cells'][$row['cell_key']] = $row['cell_count'];
            $grouped[$row['indicator_key']]['establishment_count'] = $row['establishment_count'];
            $grouped[$row['indicator_key']]['computed_at'] = $row['computed_at'];
        }

        $indicators = [];
        foreach ($grouped as $key => $data) {
            $cells = $data['cells'];
            $primary = [];
            foreach ($cells as $cell => $count) {
                if ($this->policy->suppressPrimary($count, $this->minimumCellSize)) {
                    $primary[] = (string) $cell;
                }
            }
            $hidden = array_merge($primary, $this->policy->suppressSecondary($cells, $primary, $this->minimumCellSize));
            $out = [];
            foreach ($cells as $cell => $count) {
                $suppressed = in_array((string) $cell, $hidden, true);
                $out[] = ['key' => (string) $cell, 'value' => $suppressed ? null : $count, 'suppressed' => $suppressed];
            }
            $indicators[] = [
                'key' => (string) $key,
                'establishment_count' => $data['establishment_count'],
                'computed_at' => $data['computed_at'],
                'cells' => $out,
            ];
        }

        return ['territory_id' => $territoryId, 'reference_date' => $date, 'minimum_cell_size' => $this->minimumCellSize, 'indicators' => $indicators];
    }
}
