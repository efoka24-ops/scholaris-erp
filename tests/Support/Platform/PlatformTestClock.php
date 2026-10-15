<?php

declare(strict_types=1);

namespace Tests\Support\Platform;

use Scholaris\Core\RateLimit\Clock;

/** Horloge de test pilotable (jour et heure à Douala). */
final class PlatformTestClock extends Clock
{
    private int $now;

    public function __construct(string $moment = '2026-10-09T09:00:00+01:00')
    {
        $this->now = (int) strtotime($moment);
    }

    public function now(): int
    {
        return $this->now;
    }

    public function set(string $moment): void
    {
        $this->now = (int) strtotime($moment);
    }

    public function day(string $day): void
    {
        $this->set($day . 'T09:00:00+01:00');
    }
}
