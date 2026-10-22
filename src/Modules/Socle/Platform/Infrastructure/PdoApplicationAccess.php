<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Platform\Infrastructure;

use Scholaris\Modules\Socle\Platform\Application\Catalog;
use Scholaris\Modules\Socle\Platform\Application\PlatformClock;
use Scholaris\Modules\Socle\Platform\Application\PlatformSettings;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;
use Scholaris\Modules\Socle\Platform\Contracts\RegularisationStatus;
use Scholaris\Modules\Socle\Platform\Domain\Regularisation;
use Scholaris\Modules\Socle\Platform\Domain\Subscription;

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

    /**
     * Restriction centrale du client (middleware du noyau). Le client n'est restreint que si TOUTES ses applications non
     * fermées sont bloquées pour impayé : `locked` si toutes le sont à ce niveau, sinon `read_only`. Une seule application à jour
     * (ou en simple retard) suffit à ne rien restreindre au niveau du socle : les applications bloquées refusent d'elles-mêmes.
     * Une seule lecture de tenant_applications par requête ; aucune lecture du catalogue pour un client à jour.
     */
    public function tenantLevel(string $tenantId): ?string
    {
        $rows = $this->db->all(
            'SELECT app_key, state, paid_until, trial_ends_on, grace_days_override, promise_until, regularisation, lock_level, state_reason FROM tenant_applications WHERE tenant_id = ? AND state <> ?',
            [$tenantId, self::CLOSED],
        );
        if ($rows === []) {
            return null;
        }
        $today = $this->clock->today();
        $locked = 0;
        foreach ($rows as $row) {
            $stored = (string) $row['state'];
            $dueOn = Subscription::dueOn($row['paid_until'] === null ? null : (string) $row['paid_until'], (string) $row['trial_ends_on']);
            if (in_array($stored, [self::TRIAL, self::ACTIVE], true) && strcmp($today, $dueOn) <= 0) {
                return null;
            }
            $effective = $this->evaluate($row);
            if ($effective['state'] !== self::SUSPENDED || ($effective['level'] === null && ($row['state_reason'] ?? null) !== 'unpaid')) {
                return null;
            }
            if ($effective['level'] === Regularisation::LOCKED) {
                ++$locked;
            }
        }

        return $locked === count($rows) ? RegularisationStatus::LOCKED : RegularisationStatus::READ_ONLY;
    }

    /** @return array{state: ?string, level: ?string} */
    private function effective(string $tenantId, string $appKey): array
    {
        $row = $this->db->one(
            'SELECT app_key, state, paid_until, trial_ends_on, grace_days_override, promise_until, regularisation, lock_level FROM tenant_applications WHERE tenant_id = ? AND app_key = ?',
            [$tenantId, $appKey],
        );

        return $row === null ? ['state' => null, 'level' => null] : $this->evaluate($row);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{state: string, level: ?string}
     */
    private function evaluate(array $row): array
    {
        $appKey = (string) $row['app_key'];
        $grace = $row['grace_days_override'] !== null ? (int) $row['grace_days_override'] : (int) ($this->catalog->find($appKey)['grace_days'] ?? 0);
        // Le réglage de la plateforme n'est lu que si le niveau en dépend (impayé arrivé à terme sans choix pour ce client).
        $default = $row['lock_level'] === null && $row['regularisation'] === null ? $this->settings->value(PlatformSettings::REGULARISATION_LEVEL) : 0;

        return Regularisation::effective($row, $this->clock->today(), $grace, $default);
    }
}
