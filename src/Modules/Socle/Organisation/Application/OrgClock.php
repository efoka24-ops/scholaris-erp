<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Application;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Scholaris\Core\Config\Config;
use Scholaris\Core\RateLimit\Clock;

/** Horloge du module : dates et horodatages ISO 8601 dans le fuseau de l'application (Africa/Douala). */
final class OrgClock
{
    public function __construct(
        private readonly Clock $clock,
        private readonly Config $config,
    ) {
    }

    public function timezone(): DateTimeZone
    {
        return new DateTimeZone((string) $this->config->get('app.timezone', 'Africa/Douala'));
    }

    public function now(): string
    {
        return (new DateTimeImmutable('@' . $this->clock->now()))->setTimezone($this->timezone())->format(DATE_ATOM);
    }

    /** Jour courant, au format AAAA-MM-JJ. */
    public function today(): string
    {
        return $this->day(new DateTimeImmutable('@' . $this->clock->now()));
    }

    public function day(DateTimeInterface $moment): string
    {
        return DateTimeImmutable::createFromInterface($moment)->setTimezone($this->timezone())->format('Y-m-d');
    }
}
