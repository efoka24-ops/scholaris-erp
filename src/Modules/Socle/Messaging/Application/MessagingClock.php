<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Application;

use DateTimeImmutable;
use DateTimeZone;
use Scholaris\Core\Config\Config;
use Scholaris\Core\RateLimit\Clock;

/** Horloge du module : instants dans le fuseau de l'application (Africa/Douala), injectable pour les tests. */
final class MessagingClock
{
    public function __construct(
        private readonly Clock $clock,
        private readonly Config $config,
    ) {
    }

    public function timezone(): DateTimeZone
    {
        return new DateTimeZone((string) $this->config->get('app.timezone', 'Africa/Douala'));
    }

    public function now(): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . $this->clock->now()))->setTimezone($this->timezone());
    }

    public function iso(?DateTimeImmutable $moment = null): string
    {
        return ($moment ?? $this->now())->setTimezone($this->timezone())->format(DATE_ATOM);
    }

    /** Mois courant, AAAA-MM. */
    public function month(): string
    {
        return $this->now()->format('Y-m');
    }

    /** Jour courant, AAAA-MM-JJ. */
    public function today(): string
    {
        return $this->now()->format('Y-m-d');
    }
}
