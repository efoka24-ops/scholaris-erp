<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Attendance\Application;

use DateTimeImmutable;
use DateTimeZone;
use Scholaris\Core\Config\Config;
use Scholaris\Core\RateLimit\Clock;

/** Horloge du module : horodatages ISO 8601 et jour courant dans le fuseau de l'application (Africa/Douala). */
final class AttendanceClock
{
    public function __construct(
        private readonly Clock $clock,
        private readonly Config $config,
    ) {
    }

    private function moment(): DateTimeImmutable
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

    public function plusMinutes(int $minutes): string
    {
        return $this->moment()->modify(sprintf('+%d minutes', $minutes))->format(DATE_ATOM);
    }
}
