<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Migration\Application;

/** Horloge de la reprise (Africa/Douala), figée dans les tests par un jour fixe. */
class MigrationClock
{
    public function __construct(private ?string $fixedDay = null)
    {
    }

    public function freeze(?string $day): void
    {
        $this->fixedDay = $day;
    }

    public function now(): string
    {
        $zone = new \DateTimeZone('Africa/Douala');
        $at = $this->fixedDay === null ? new \DateTimeImmutable('now', $zone) : new \DateTimeImmutable($this->fixedDay . ' 08:00:00', $zone);

        return $at->format(DATE_ATOM);
    }
}
