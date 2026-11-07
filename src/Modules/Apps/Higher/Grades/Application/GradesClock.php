<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Grades\Application;

use DateTimeImmutable;
use DateTimeZone;

/** Horloge des notes (fuseau Africa/Douala), figée dans les tests par un jour fixe. */
class GradesClock
{
    private const ZONE = 'Africa/Douala';

    public function __construct(private ?string $fixedDay = null)
    {
    }

    public function freeze(?string $day): void
    {
        $this->fixedDay = $day;
    }

    public function at(): DateTimeImmutable
    {
        $zone = new DateTimeZone(self::ZONE);

        return $this->fixedDay === null ? new DateTimeImmutable('now', $zone) : new DateTimeImmutable($this->fixedDay . ' 08:00:00', $zone);
    }

    public function today(): string
    {
        return $this->at()->format('Y-m-d');
    }

    public function now(): string
    {
        return $this->at()->format(DATE_ATOM);
    }
}
