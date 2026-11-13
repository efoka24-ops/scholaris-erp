<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Dashboards\Application;

use Scholaris\Modules\Apps\Secondary\Dashboards\Domain\DashboardDefaults;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;
use Throwable;

/** Cibles et réglages effectifs des tableaux de bord : valeurs de DashboardDefaults, remplacées clé par clé par les paramètres. */
final class DashboardSettings
{
    public function __construct(private readonly SettingsReader $settings)
    {
    }

    /** @return array<string, array{target: int|float, direction: string, unit: string}> */
    public function targets(string $tenantId, string $establishmentId): array
    {
        $targets = DashboardDefaults::TARGETS;
        foreach ($this->read($tenantId, $establishmentId, 'secondary.dashboards.targets') as $code => $value) {
            if (isset($targets[$code]) && is_numeric($value)) {
                $targets[$code]['target'] = $value + 0;
            }
        }

        return $targets;
    }

    public function reportCardDays(string $tenantId, string $establishmentId): int
    {
        $value = $this->read($tenantId, $establishmentId, 'secondary.dashboards.settings')['report_card_days'] ?? null;

        return is_int($value) && $value >= 0 ? $value : DashboardDefaults::SETTINGS['report_card_days'];
    }

    public function passingAverage(string $tenantId, string $establishmentId): float
    {
        $value = $this->read($tenantId, $establishmentId, 'secondary.dashboards.settings')['passing_average'] ?? null;

        return is_numeric($value) ? (float) $value : DashboardDefaults::SETTINGS['passing_average'];
    }

    /** @return list<int> bornes supérieures croissantes des tranches d'âge */
    public function ageBands(string $tenantId, string $establishmentId): array
    {
        $value = $this->read($tenantId, $establishmentId, 'secondary.dashboards.settings')['age_bands'] ?? null;
        if (!is_array($value) || $value === [] || array_filter($value, static fn (mixed $v): bool => !is_int($v)) !== []) {
            return DashboardDefaults::SETTINGS['age_bands'];
        }
        /** @var list<int> $bands */
        $bands = array_values(array_unique($value));
        sort($bands);

        return $bands;
    }

    /** Échéance de transmission des données de fin d'année à cartescolaire.cm (RG-BUL-4, Q1) : vide = pas d'alerte. */
    public function yearEndDeadline(string $tenantId, string $establishmentId): ?string
    {
        try {
            $value = $this->settings->value($tenantId, $establishmentId, 'secondary.carte_scolaire.year_end_deadline');
        } catch (Throwable) {
            return null;
        }

        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }

    /** @return array<string, mixed> */
    private function read(string $tenantId, string $establishmentId, string $key): array
    {
        try {
            $value = $this->settings->value($tenantId, $establishmentId, $key, []);
        } catch (Throwable) {
            return [];
        }
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return is_array($value) ? $value : [];
    }
}
