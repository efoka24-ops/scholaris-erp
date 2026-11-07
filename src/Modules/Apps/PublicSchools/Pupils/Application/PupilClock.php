<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Pupils\Application;

use DateTimeImmutable;
use DateTimeZone;
use Scholaris\Core\Config\Config;
use Scholaris\Core\RateLimit\Clock;

/** Horloge du module des élèves : jours AAAA-MM-JJ et horodatages ISO 8601 au fuseau de l'application. */
final class PupilClock
{
    public function __construct(private readonly Clock $clock, private readonly Config $config)
    {
    }

    public function moment(): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . $this->clock->now()))
            ->setTimezone(new DateTimeZone((string) $this->config->get('app.timezone', 'Africa/Douala')));
    }

    public function now(): string
    {
        return $this->moment()->format(DATE_ATOM);
    }

    public function today(): string
    {
        return $this->moment()->format('Y-m-d');
    }
}
