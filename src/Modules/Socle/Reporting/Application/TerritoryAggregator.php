<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Reporting\Application;

use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentDirectory;
use Scholaris\Modules\Socle\Organisation\Contracts\TerritoryDirectory;
use Scholaris\Modules\Socle\Reporting\Contracts\TerritoryAggregateProvider;
use Scholaris\Modules\Socle\Reporting\Infrastructure\TerritoryAggregateRepository;

/**
 * Calcul des agrégats par territoire (PP4) : pour chaque établissement du client, chaque fournisseur
 * `reporting.territory_aggregates.*` donne ses effectifs, additionnés dans le territoire de rattachement à la date de
 * référence (RG-TER-4) et dans tous ses parents. Un établissement sans rattachement n'est compté nulle part.
 */
final class TerritoryAggregator
{
    public const PROVIDERS_PREFIX = 'reporting.territory_aggregates.';

    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly EstablishmentDirectory $establishments,
        private readonly TerritoryDirectory $territories,
        private readonly TerritoryAggregateRepository $repository,
        private readonly ReportingClock $clock,
    ) {
    }

    /**
     * @return array<string, int> client => lignes écrites
     */
    public function run(string $referenceDate, ?string $tenantId = null): array
    {
        $results = [];
        foreach ($tenantId !== null ? [$tenantId] : $this->repository->activeTenants() as $tenant) {
            $results[$tenant] = $this->runTenant($tenant, $referenceDate);
        }

        return $results;
    }

    private function runTenant(string $tenantId, string $referenceDate): int
    {
        $providers = $this->registry->resolve(self::PROVIDERS_PREFIX, TerritoryAggregateProvider::class);
        $at = $referenceDate . 'T12:00:00+00:00';
        /** @var array<string, array<string, array{cells: array<string, int>, establishments: int}>> $byTerritory */
        $byTerritory = [];
        foreach ($this->establishments->forTenant($tenantId) as $establishment) {
            $territory = $this->territories->territoryOf($tenantId, $establishment->id, $at);
            if ($territory === null) {
                continue;
            }
            $lineage = array_map(static fn ($info): string => $info->id, $this->territories->lineage($tenantId, $territory->id, $at));
            foreach ($providers as $provider) {
                $indicator = $provider->indicator();
                $counts = $provider->countsFor($tenantId, $establishment->id, $referenceDate);
                foreach ($lineage as $territoryId) {
                    $entry = $byTerritory[$territoryId][$indicator] ?? ['cells' => [], 'establishments' => 0];
                    ++$entry['establishments'];
                    foreach ($counts as $cell => $count) {
                        $entry['cells'][(string) $cell] = ($entry['cells'][(string) $cell] ?? 0) + max(0, $count);
                    }
                    $byTerritory[$territoryId][$indicator] = $entry;
                }
            }
        }

        return $this->repository->replace($tenantId, $referenceDate, $byTerritory, $this->clock->iso());
    }
}
