<?php

declare(strict_types=1);

namespace Tests\Support\Assessment;

use Scholaris\Core\RateLimit\Clock;

/** Horloge figée (8 octobre 2026, 09 h à Douala). */
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
}
