<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Admissions\Application;

use DateTimeImmutable;
use DateTimeZone;

/** Horloge des admissions (fuseau Africa/Douala), figée dans les tests par un jour fixe. */
class AdmissionsClock
{
    private const ZONE = 'Africa/Douala';

    public function __construct(private ?string $fixedDay = null)
    {
    }

    /** Fige l'horloge (tests, rejeu d'une tâche planifiée pour un jour donné). */
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

    public function plusDays(int $days): string
    {
        return $this->at()->modify(sprintf('%+d days', $days))->format('Y-m-d');
    }
}
