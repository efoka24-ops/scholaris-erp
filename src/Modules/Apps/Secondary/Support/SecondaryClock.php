<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Support;

use DateTimeImmutable;
use DateTimeZone;

/** Horloge de l'application secondaire (Africa/Douala), figée dans les tests par un jour fixe. */
class SecondaryClock
{
    private const ZONE = 'Africa/Douala';

    public function __construct(private readonly ?string $fixedDay = null)
    {
    }

    public function at(): DateTimeImmutable
    {
        $zone = new DateTimeZone(self::ZONE);

        return $this->fixedDay === null ? new DateTimeImmutable('now', $zone) : new DateTimeImmutable($this->fixedDay . ' 08:00:00', $zone);
    }

    /** Jour courant AAAA-MM-JJ. */
    public function today(): string
    {
        return $this->at()->format('Y-m-d');
    }

    /** Instant courant ISO 8601. */
    public function now(): string
    {
        return $this->at()->format(DATE_ATOM);
    }
}
