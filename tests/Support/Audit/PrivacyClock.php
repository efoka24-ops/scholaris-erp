<?php

declare(strict_types=1);

namespace Tests\Support\Audit;

use Scholaris\Core\RateLimit\Clock;

final class PrivacyClock extends Clock
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
