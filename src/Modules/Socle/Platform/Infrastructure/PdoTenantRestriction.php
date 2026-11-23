<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Platform\Infrastructure;

use Scholaris\Core\Http\TenantRestriction;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;
use Scholaris\Modules\Socle\Platform\Contracts\RegularisationStatus;

/**
 * Restriction du client lue par le middleware du noyau : le niveau de blocage de tout le client, recalculé à chaque requête
 * (déblocage immédiat). Le droit d'usage est celui du conteneur (ApplicationAccess) : une doublure qui ne sait pas dire le niveau
 * de régularisation (tests des applications) ne restreint rien.
 */
final class PdoTenantRestriction implements TenantRestriction
{
    public function __construct(private readonly ApplicationAccess $access)
    {
    }

    public function levelFor(string $tenantId): ?string
    {
        return $this->access instanceof RegularisationStatus ? $this->access->tenantLevel($tenantId) : null;
    }
}
