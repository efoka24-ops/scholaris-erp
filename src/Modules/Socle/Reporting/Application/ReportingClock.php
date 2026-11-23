<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Reporting\Application;

use Scholaris\Core\RateLimit\Clock;

/** Date du jour (fuseau de l'application) et lundi de la semaine en cours. */
final class ReportingClock
{
    public function __construct(private readonly Clock $clock)
    {
    }

    public function today(): string
    {
        return date('Y-m-d', $this->clock->now());
    }

    public function weekStart(): string
    {
        $now = $this->clock->now();

        return date('Y-m-d', $now - ((int) date('N', $now) - 1) * 86400);
    }

    public function iso(): string
    {
        return date('c', $this->clock->now());
    }
}
