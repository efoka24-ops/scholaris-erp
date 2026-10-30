<?php

declare(strict_types=1);

namespace Scholaris\Core\RateLimit;

/** Horloge injectable (secondes Unix), substituable dans les tests. */
class Clock
{
    public function now(): int
    {
        return time();
    }
}
