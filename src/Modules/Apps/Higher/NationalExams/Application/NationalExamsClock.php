<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\NationalExams\Application;

use DateTimeImmutable;
use DateTimeZone;

/** Horloge des examens nationaux (fuseau Africa/Douala), figée dans les tests par un jour fixe. */
class NationalExamsClock
{
    private const ZONE = 'Africa/Douala';

    public function __construct(private ?string $fixedDay = null)
    {
    }

    public function freeze(?string $day): void
    {
        $this->fixedDay = $day;
    }

    public function today(): string
    {
        return $this->at()->format('Y-m-d');
    }

    public function now(): string
    {
        return $this->at()->format(DATE_ATOM);
    }

    private function at(): DateTimeImmutable
    {
        $zone = new DateTimeZone(self::ZONE);

        return $this->fixedDay === null ? new DateTimeImmutable('now', $zone) : new DateTimeImmutable($this->fixedDay . ' 08:00:00', $zone);
    }
}
