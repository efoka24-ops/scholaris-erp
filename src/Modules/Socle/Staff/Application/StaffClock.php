<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Staff\Application;

use DateTimeImmutable;
use DateTimeZone;
use Scholaris\Core\Config\Config;
use Scholaris\Core\RateLimit\Clock;

/** Horloge du module : horodatages ISO 8601 et jours AAAA-MM-JJ au fuseau de l'application (Africa/Douala). */
final class StaffClock
{
    public function __construct(private readonly Clock $clock, private readonly Config $config)
    {
    }

    public function timestamp(): int
    {
        return $this->clock->now();
    }

    public function now(): string
    {
        return $this->moment()->format(DATE_ATOM);
    }

    public function iso(int $timestamp): string
    {
        return (new DateTimeImmutable('@' . $timestamp))
            ->setTimezone(new DateTimeZone((string) $this->config->get('app.timezone', 'Africa/Douala')))
            ->format(DATE_ATOM);
    }

    public function today(): string
    {
        return $this->moment()->format('Y-m-d');
    }

    public function inDays(int $days): string
    {
        return $this->moment()->modify(sprintf('%+d days', $days))->format('Y-m-d');
    }

    private function moment(): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . $this->clock->now()))
            ->setTimezone(new DateTimeZone((string) $this->config->get('app.timezone', 'Africa/Douala')));
    }
}
