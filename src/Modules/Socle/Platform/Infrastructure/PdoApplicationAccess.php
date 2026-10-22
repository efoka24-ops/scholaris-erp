<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Platform\Infrastructure;

use Scholaris\Modules\Socle\Platform\Application\Catalog;
use Scholaris\Modules\Socle\Platform\Application\PlatformClock;
use Scholaris\Modules\Socle\Platform\Application\PlatformSettings;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;
use Scholaris\Modules\Socle\Platform\Contracts\RegularisationStatus;
use Scholaris\Modules\Socle\Platform\Domain\Regularisation;

/**
 * État d'un droit d'usage, recalculé à la date du jour : un impayé au-delà du délai de grâce (propre au client s'il est
 * surchargé) et de l'éventuelle promesse de paiement est traité comme suspendu même si la commande quotidienne
 * `platform:billing-run` n'est pas encore passée. Au niveau `locked` du mode régularisation, l'application n'est plus
 * lisible : les applications refusent alors lecture et écriture (le client n'a plus que l'écran de régularisation).
 */
final class PdoApplicationAccess implements ApplicationAccess, RegularisationStatus
{
    public function __construct(
        private readonly PlatformDb $db,
        private readonly Catalog $catalog,
        private readonly PlatformClock $clock,
        private readonly PlatformSettings $settings,
    ) {
    }

    public function state(string $tenantId, string $appKey): ?string
    {
        return $this->effective($tenantId, $appKey)['state'];
    }

    public function allowsWrites(string $tenantId, string $appKey): bool
    {
        return in_array($this->state($tenantId, $appKey), [self::TRIAL, self::ACTIVE, self::PAST_DUE], true);
    }

    public function allowsReads(string $tenantId, string $appKey): bool
    {
        $effective = $this->effective($tenantId, $appKey);

        return $effective['state'] !== null && $effective['state'] !== self::CLOSED && $effective['level'] !== Regularisation::LOCKED;
    }

    public function level(string $tenantId, string $appKey): ?string
    {
        return $this->effective($tenantId, $appKey)['level'];
    }

    /** @return array{state: ?string, level: ?string} */
    private function effective(string $tenantId, string $appKey): array
    {
        $row = $this->db->one(
            'SELECT state, paid_until, trial_ends_on, grace_days_override, promise_until, regularisation, lock_level FROM tenant_applications WHERE tenant_id = ? AND app_key = ?',
            [$tenantId, $appKey],
        );
        if ($row === null) {
            return ['state' => null, 'level' => null];
        }
        $grace = $row['grace_days_override'] !== null ? (int) $row['grace_days_override'] : (int) ($this->catalog->find($appKey)['grace_days'] ?? 0);
        // Le réglage de la plateforme n'est lu que si le niveau en dépend (impayé arrivé à terme sans choix pour ce client).
        $default = $row['lock_level'] === null && $row['regularisation'] === null ? $this->settings->value(PlatformSettings::REGULARISATION_LEVEL) : 0;

        return Regularisation::effective($row, $this->clock->today(), $grace, $default);
    }
}
