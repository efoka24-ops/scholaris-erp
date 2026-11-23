<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Sync\Application;

use DateTimeImmutable;
use DateTimeZone;
use Scholaris\Core\Config\Config;
use Scholaris\Core\RateLimit\Clock;

/** Horloge du module : horodatages ISO 8601 dans le fuseau de l'application (Africa/Douala, sans changement d'heure). */
final class SyncClock
{
    public function __construct(
        private readonly Clock $clock,
        private readonly Config $config,
    ) {
    }

    public function now(): string
    {
        return $this->at(0);
    }

    /** Horodatage ISO 8601 situé $minutes minutes avant maintenant. */
    public function minutesAgo(int $minutes): string
    {
        return $this->at(-60 * $minutes);
    }

    private function at(int $offsetSeconds): string
    {
        return (new DateTimeImmutable('@' . ($this->clock->now() + $offsetSeconds)))
            ->setTimezone(new DateTimeZone((string) $this->config->get('app.timezone', 'Africa/Douala')))
            ->format(DATE_ATOM);
    }
}
