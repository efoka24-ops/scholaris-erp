<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Reporting\Application;

use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentDirectory;
use Scholaris\Modules\Socle\Organisation\Contracts\ModuleAvailability;
use Scholaris\Modules\Socle\Reporting\Contracts\DashboardDefinition;
use Scholaris\Modules\Socle\Reporting\Contracts\Indicator;
use Scholaris\Modules\Socle\Reporting\Contracts\IndicatorProvider;
use Scholaris\Modules\Socle\Reporting\Contracts\IndicatorScope;
use Scholaris\Modules\Socle\Reporting\Domain\DashboardKind;
use Throwable;

/** Compose un tableau de bord : fournisseurs enregistrés, repli « indisponible » pour chaque indicateur attendu sans source. */
final class IndicatorService
{
    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly ModuleAvailability $modules,
        private readonly EstablishmentDirectory $establishments,
        private readonly ReportingClock $clock,
    ) {
    }

    /** @return array<string, mixed> */
    public function build(DashboardKind $kind, string $tenantId, ?string $establishmentId): array
    {
        if ($kind === DashboardKind::Groupe) {
            return $this->group($tenantId);
        }
        $degraded = [];
        $indicators = $this->forEstablishment($kind->expected(), $tenantId, (string) $establishmentId, $degraded);

        return [
            'kind' => $kind->value,
            'scope' => ['type' => 'establishment', 'establishment_id' => $establishmentId],
            'generated_at' => $this->clock->iso(),
            'indicators' => array_map(static fn (Indicator $i): array => $i->toArray(), $indicators),
            'meta' => ['degraded_providers' => $degraded],
        ];
    }

    /**
     * Tableau de bord déclaré par une application (S21), pour un établissement et éventuellement une école interne.
     *
     * @return array<string, mixed>
     */
    public function buildDefinition(DashboardDefinition $definition, string $tenantId, string $establishmentId, ?string $orgUnitId = null): array
    {
        $degraded = [];
        $indicators = $this->forEstablishment($definition->expected(), $tenantId, $establishmentId, $degraded, $orgUnitId);
        $scope = ['type' => 'establishment', 'establishment_id' => $establishmentId];

        return [
            'kind' => $definition->key(),
            'label' => $definition->label(),
            'scope' => $orgUnitId === null ? $scope : $scope + ['org_unit_id' => $orgUnitId],
            'generated_at' => $this->clock->iso(),
            'indicators' => array_map(static fn (Indicator $i): array => $i->toArray(), $indicators),
            'meta' => ['degraded_providers' => $degraded],
        ];
    }

    /** @return array<string, mixed> */
    private function group(string $tenantId): array
    {
        $degraded = [];
        $perEstablishment = [];
        $totals = [];
        foreach ($this->establishments->forTenant($tenantId) as $establishment) {
            $indicators = $this->forEstablishment(DashboardKind::Groupe->expected(), $tenantId, $establishment->id, $degraded);
            $perEstablishment[] = [
                'establishment_id' => $establishment->id,
                'name' => $establishment->name,
                'indicators' => array_map(static fn (Indicator $i): array => $i->toArray(), $indicators),
            ];
            foreach ($indicators as $indicator) {
                if (in_array($indicator->key, DashboardKind::ADDITIVE, true)) {
                    $totals[$indicator->key][] = $indicator;
                }
            }
        }

        $consolidated = [];
        foreach (DashboardKind::DIRECTION as $key => $label) {
            if (!in_array($key, DashboardKind::ADDITIVE, true)) {
                continue;
            }
            $items = $totals[$key] ?? [];
            $available = array_values(array_filter($items, static fn (Indicator $i): bool => $i->available));
            $consolidated[] = $available === []
                ? Indicator::unavailable($key, $label, 'Aucun établissement ne fournit cet indicateur.')->toArray()
                : Indicator::of($key, $label, array_sum(array_map(static fn (Indicator $i): int|float => $i->value ?? 0, $available)), $available[0]->unit, [
                    'establishments_counted' => count($available),
                    'establishments_total' => count($items),
                ])->toArray();
        }

        return [
            'kind' => DashboardKind::Groupe->value,
            'scope' => ['type' => 'group'],
            'generated_at' => $this->clock->iso(),
            'consolidated' => $consolidated,
            'establishments' => $perEstablishment,
            'meta' => ['degraded_providers' => array_values(array_unique($degraded))],
        ];
    }

    /**
     * @param array<string, string> $expected
     * @param list<string> $degraded
     * @return list<Indicator>
     */
    private function forEstablishment(array $expected, string $tenantId, string $establishmentId, array &$degraded, ?string $orgUnitId = null): array
    {
        $scope = new IndicatorScope($tenantId, $establishmentId, $this->clock->today(), $this->clock->weekStart(), $orgUnitId);
        $produced = [];
        foreach ($this->registry->resolve(ProviderRegistry::INDICATORS, IndicatorProvider::class) as $provider) {
            if (!$this->modules->isEnabled($tenantId, $establishmentId, $provider->module())) {
                continue;
            }
            try {
                foreach ($provider->indicators($scope) as $indicator) {
                    $produced[$indicator->key] = $indicator;
                }
            } catch (Throwable) {
                $degraded[] = $provider::class;
            }
        }

        $out = [];
        foreach ($expected as $key => $label) {
            $out[] = $produced[$key] ?? Indicator::unavailable($key, $label, 'Aucune source ne fournit encore cet indicateur.');
            unset($produced[$key]);
        }

        return [...$out, ...array_values($produced)];
    }
}
