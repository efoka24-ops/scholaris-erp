<?php

declare(strict_types=1);

namespace Tests\Support\Reporting;

use Scholaris\Core\RateLimit\Clock;

/** Horloge figée : jeudi 8 octobre 2026, 09 h à Douala. */
final class FixedClock extends Clock
{
    public function now(): int
    {
        return (int) strtotime('2026-10-08T09:00:00+01:00');
    }
}
