<?php

declare(strict_types=1);

namespace Tests\Support\Attendance;

use Scholaris\Core\RateLimit\Clock;

/** Horloge figée du module Attendance (par défaut : 8 octobre 2026, 09 h à Douala). */
final class FixedClock extends Clock
{
    private int $now;

    public function __construct(string $moment = '2026-10-08T09:00:00+01:00')
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
}
