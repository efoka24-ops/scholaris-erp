<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Scheduling\Application;

use DateTimeImmutable;
use DateTimeZone;
use Scholaris\Core\Config\Config;
use Scholaris\Core\RateLimit\Clock;

/** Horodatages ISO 8601 au fuseau de l'application (Africa/Douala). */
final class SchedulingClock
{
    public function __construct(private readonly Clock $clock, private readonly Config $config)
    {
    }

    public function now(): string
    {
        return (new DateTimeImmutable('@' . $this->clock->now()))
            ->setTimezone(new DateTimeZone((string) $this->config->get('app.timezone', 'Africa/Douala')))
            ->format(DATE_ATOM);
    }
}
