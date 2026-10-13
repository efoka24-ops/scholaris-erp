<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Documents\Application;

use DateTimeImmutable;
use DateTimeZone;
use Scholaris\Core\Config\Config;
use Scholaris\Core\RateLimit\Clock;

/** Heure du module : ISO 8601 dans le fuseau de l'application (Africa/Douala), horloge substituable en test. */
final class DocumentClock
{
    public function __construct(private readonly Clock $clock, private readonly Config $config)
    {
    }

    public function moment(): DateTimeImmutable
    {
        $zone = new DateTimeZone((string) $this->config->get('app.timezone', 'Africa/Douala'));

        return (new DateTimeImmutable('@' . $this->clock->now()))->setTimezone($zone);
    }

    public function now(): string
    {
        return $this->moment()->format(DATE_ATOM);
    }

    public function year(): int
    {
        return (int) $this->moment()->format('Y');
    }

    public function verificationBase(): string
    {
        return rtrim((string) $this->config->get('app.url', ''), '/') . (string) $this->config->get('app.api_prefix', '/api/v1') . '/public/verify/';
    }
}
