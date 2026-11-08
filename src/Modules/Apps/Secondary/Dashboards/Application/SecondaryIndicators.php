<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Dashboards\Application;

use Scholaris\Modules\Apps\Secondary\Setup\Contracts\EstablishmentProfiles;
use Scholaris\Modules\Socle\Reporting\Contracts\Indicator;
use Scholaris\Modules\Socle\Reporting\Contracts\IndicatorProvider;
use Scholaris\Modules\Socle\Reporting\Contracts\IndicatorScope;
use Throwable;

/**
 * Indicateurs du secondaire pour Reporting (`reporting.indicators.secondary`, T970) : objectifs O1 à O7 de l'établissement, servis
 * par le tableau de bord `secondary` du socle (GET /dashboards/secondary, `secondary.dashboard.read`). Établissement hors secondaire :
 * aucun indicateur (les tableaux de bord des autres applications ne sont pas touchés) ; année introuvable : indisponibles.
 */
final class SecondaryIndicators implements IndicatorProvider
{
    public const EXPECTED = [
        'secondary.o1' => ObjectiveCalculator::LABELS['O1'],
        'secondary.o2' => ObjectiveCalculator::LABELS['O2'],
        'secondary.o3' => ObjectiveCalculator::LABELS['O3'],
        'secondary.o4' => ObjectiveCalculator::LABELS['O4'],
        'secondary.o5' => ObjectiveCalculator::LABELS['O5'],
        'secondary.o6' => ObjectiveCalculator::LABELS['O6'],
        'secondary.o7' => ObjectiveCalculator::LABELS['O7'],
    ];

    public function __construct(
        private readonly EstablishmentProfiles $profiles,
        private readonly DashboardService $dashboards,
        private readonly ObjectiveCalculator $objectives,
    ) {
    }

    public function module(): string
    {
        return 'people';
    }

    public function indicators(IndicatorScope $scope): array
    {
        try {
            $profile = $this->profiles->forEstablishment($scope->tenantId, $scope->establishmentId);
        } catch (Throwable) {
            return [];
        }
        try {
            [, $period, $roster] = $this->dashboards->frame($scope->tenantId, $scope->establishmentId, []);
        } catch (Throwable) {
            return array_map(static fn (string $key, string $label): Indicator => Indicator::unavailable($key, $label, 'Aucune année scolaire en cours.'), array_keys(self::EXPECTED), self::EXPECTED);
        }
        $out = [];
        foreach ($this->objectives->compute($scope->tenantId, $scope->establishmentId, $profile, $period, $roster)['objectives'] as $objective) {
            $key = 'secondary.' . strtolower((string) $objective['code']);
            $value = $objective['value'];
            $out[] = is_int($value) || is_float($value)
                ? Indicator::of($key, (string) $objective['label'], $value, (string) $objective['unit'], self::scalars($objective))
                : Indicator::unavailable($key, (string) $objective['label'], (string) $objective['reason'], (string) $objective['unit']);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $objective
     * @return array<string, scalar|null>
     */
    private static function scalars(array $objective): array
    {
        $out = ['target' => is_int($objective['target']) || is_float($objective['target']) ? $objective['target'] : null, 'met' => is_bool($objective['met']) ? $objective['met'] : null];
        foreach (is_array($objective['detail']) ? $objective['detail'] : [] as $name => $value) {
            if ($value === null || is_scalar($value)) {
                $out[(string) $name] = $value;
            }
        }

        return $out;
    }
}
