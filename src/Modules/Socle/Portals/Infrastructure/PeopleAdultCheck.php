<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Portals\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use Scholaris\Core\Config\Config;
use Scholaris\Core\RateLimit\Clock;
use Scholaris\Modules\Socle\People\Contracts\BirthInfo;
use Scholaris\Modules\Socle\Portals\Contracts\AdultLearnerCheck;

/** Majorité d'après le contrat People\BirthInfo, à la date du jour ; null si la date de naissance est inconnue. */
final class PeopleAdultCheck implements AdultLearnerCheck
{
    public function __construct(
        private readonly BirthInfo $births,
        private readonly Clock $clock,
        private readonly Config $config,
    ) {
    }

    public function isAdult(string $tenantId, string $personId): ?bool
    {
        $today = (new DateTimeImmutable('@' . $this->clock->now()))
            ->setTimezone(new DateTimeZone((string) $this->config->get('app.timezone', 'Africa/Douala')))
            ->format('Y-m-d');

        return $this->births->isAdult($tenantId, $personId, $today);
    }
}
