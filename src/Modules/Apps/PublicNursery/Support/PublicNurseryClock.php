<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicNursery\Support;

use DateTimeImmutable;
use DateTimeZone;

/** Horloge de la Maternelle publique (fuseau du pays), figée dans les tests par un instant fixe. */
class PublicNurseryClock
{
    public function __construct(private readonly ?string $fixed = null)
    {
    }

    public function at(): DateTimeImmutable
    {
        $zone = new DateTimeZone('Africa/Douala');

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
