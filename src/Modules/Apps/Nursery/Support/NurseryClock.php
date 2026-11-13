<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Support;

use DateTimeImmutable;
use DateTimeZone;

/** Horloge de la Maternelle privée (fuseau du pays), figée dans les tests par un instant fixe. */
class NurseryClock
{
    private const ZONE = 'Africa/Douala';

    /** @param string|null $fixed instant ISO 8601 fixe (tests) */
    public function __construct(private readonly ?string $fixed = null)
    {
    }

    public function at(): DateTimeImmutable
    {
        $zone = new DateTimeZone(self::ZONE);

        return $this->fixed === null ? new DateTimeImmutable('now', $zone) : (new DateTimeImmutable($this->fixed))->setTimezone($zone);
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
