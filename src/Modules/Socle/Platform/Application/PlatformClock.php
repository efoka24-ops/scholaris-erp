<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Platform\Application;

use DateTimeImmutable;
use DateTimeZone;
use Scholaris\Core\Config\Config;
use Scholaris\Core\RateLimit\Clock;

/** Horloge du module (remplaçable en test par l'horloge du noyau) : horodatages ISO 8601 et jour courant à Douala. */
final class PlatformClock
{
    public function __construct(
        private readonly Clock $clock,
        private readonly Config $config,
    ) {
    }

    public function iso(): string
    {
        return $this->moment()->format(DATE_ATOM);
    }

    public function today(): string
    {
        return $this->moment()->format('Y-m-d');
    }

    public function year(): int
    {
        return (int) $this->moment()->format('Y');
    }

    private function moment(): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . $this->clock->now()))
            ->setTimezone(new DateTimeZone((string) $this->config->get('app.timezone', 'Africa/Douala')));
    }
}
