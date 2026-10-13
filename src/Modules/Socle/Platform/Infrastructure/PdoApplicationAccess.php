<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Platform\Infrastructure;

use Scholaris\Modules\Socle\Platform\Application\Catalog;
use Scholaris\Modules\Socle\Platform\Application\PlatformClock;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;
use Scholaris\Modules\Socle\Platform\Domain\Subscription;

/**
 * État d'un droit d'usage, recalculé à la date du jour : un impayé au-delà du délai de grâce est traité comme suspendu
 * même si la commande quotidienne `platform:billing-run` n'est pas encore passée.
 */
final class PdoApplicationAccess implements ApplicationAccess
{
    public function __construct(
        private readonly PlatformDb $db,
        private readonly Catalog $catalog,
        private readonly PlatformClock $clock,
    ) {
    }

    public function state(string $tenantId, string $appKey): ?string
    {
        $row = $this->db->one('SELECT state, paid_until, trial_ends_on FROM tenant_applications WHERE tenant_id = ? AND app_key = ?', [$tenantId, $appKey]);
        if ($row === null) {
            return null;
        }
        $grace = (int) ($this->catalog->find($appKey)['grace_days'] ?? 0);
        $dueOn = Subscription::dueOn($row['paid_until'] === null ? null : (string) $row['paid_until'], (string) $row['trial_ends_on']);

        return Subscription::nextState((string) $row['state'], $this->clock->today(), $dueOn, $grace);
    }

    public function allowsWrites(string $tenantId, string $appKey): bool
    {
        return in_array($this->state($tenantId, $appKey), [self::TRIAL, self::ACTIVE, self::PAST_DUE], true);
    }

    public function allowsReads(string $tenantId, string $appKey): bool
    {
        $state = $this->state($tenantId, $appKey);

        return $state !== null && $state !== self::CLOSED;
    }
}
