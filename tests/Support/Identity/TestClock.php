<?php

declare(strict_types=1);

namespace Tests\Support\Identity;

use Scholaris\Core\RateLimit\Clock;

/** Horloge pilotable : fixe, avancée à la demande. */
final class TestClock extends Clock
{
    public function __construct(private int $now = 1791450000) // 2026-10-08 08:20 UTC environ
    {
    }

    public function now(): int
    {
        return $this->now;
    }

    public function advance(int $seconds): void
    {
        $this->now += $seconds;
    }
}
