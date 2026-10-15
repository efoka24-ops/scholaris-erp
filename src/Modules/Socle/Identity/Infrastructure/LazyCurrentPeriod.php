<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Infrastructure;

use Psr\Container\ContainerInterface;
use Scholaris\Modules\Socle\Organisation\Contracts\CurrentPeriod;
use Scholaris\Modules\Socle\Organisation\Contracts\PeriodInfo;

/**
 * Résout la période en cours d'Organisation au premier usage ; sans liaison (module absent), aucune période n'est connue.
 */
final class LazyCurrentPeriod implements CurrentPeriod
{
    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function at(string $tenantId, string $establishmentId, string $date): ?PeriodInfo
    {
        return $this->container->has(CurrentPeriod::class)
            ? $this->container->get(CurrentPeriod::class)->at($tenantId, $establishmentId, $date)
            : null;
    }
}
