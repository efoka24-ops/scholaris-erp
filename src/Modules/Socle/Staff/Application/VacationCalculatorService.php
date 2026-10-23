<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Staff\Application;

use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;
use Scholaris\Modules\Socle\Staff\Contracts\VacationCalculator;
use Scholaris\Modules\Socle\Staff\Domain\SessionKind;
use Scholaris\Modules\Socle\Staff\Domain\VacationCalc;

/**
 * Coefficients d'équivalent TD paramétrables (D14) : `staff.hetd.cm`, `staff.hetd.td`, `staff.hetd.tp`, `staff.hetd.other`
 * (nombres décimaux, ex. 1.5), hérités plateforme > client > établissement. Valeur absente ou hors de ]0 ; 10] : défaut du socle
 * (1,5 ; 1 ; 0,75 ; 1). Taux par catégorie et niveau (D15).
 */
final class VacationCalculatorService implements VacationCalculator
{
    public const SETTING_PREFIX = 'staff.hetd.';

    public function __construct(private readonly SettingsReader $settings, private readonly RateService $rates)
    {
    }

    public function coefficients(string $tenantId, string $establishmentId): array
    {
        $out = [];
        foreach (SessionKind::cases() as $kind) {
            $default = $kind->hetdPermille();
            $value = $this->settings->value($tenantId, $establishmentId, self::SETTING_PREFIX . $kind->value, null);
            $permille = is_numeric($value) ? (int) round(((float) $value) * 1000) : $default;
            $out[$kind->value] = $permille > 0 && $permille <= 10000 ? $permille : $default;
        }

        return ['cm' => $out['cm'], 'td' => $out['td'], 'tp' => $out['tp'], 'other' => $out['other']];
    }

    public function hetdMilli(string $tenantId, string $establishmentId, array $minutesByKind): int
    {
        return VacationCalc::hetdMilli($minutesByKind, $this->coefficients($tenantId, $establishmentId));
    }

    public function amount(int $hetdMilli, int $hourlyRate): int
    {
        return VacationCalc::amount($hetdMilli, $hourlyRate);
    }

    public function hourlyRate(string $tenantId, string $establishmentId, string $category, ?string $level = null): ?int
    {
        $rate = $this->rates->forCategory($tenantId, $establishmentId, $category, $level, true);

        return $rate === null ? null : (int) $rate['hourly_rate'];
    }
}
