<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Application;

use DateTimeImmutable;
use DateTimeZone;
use Scholaris\Core\RateLimit\Clock;

/**
 * Instant courant au fuseau de la plateforme (Africa/Douala, sans changement d'heure) et formatage ISO 8601.
 * Toutes les dates stockées passent par cette classe : leur ordre alphabétique est leur ordre chronologique.
 */
final class Moment
{
    private readonly DateTimeZone $zone;

    public function __construct(
        private readonly Clock $clock,
        string $timezone = 'Africa/Douala',
    ) {
        $this->zone = new DateTimeZone($timezone);
    }

    public function timestamp(): int
    {
        return $this->clock->now();
    }

    public function now(): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . $this->clock->now()))->setTimezone($this->zone);
    }

    public function iso(): string
    {
        return $this->now()->format(DATE_ATOM);
    }

    /** Date ISO située à $seconds secondes de maintenant (négatif = passé). */
    public function in(int $seconds): string
    {
        return $this->at($this->clock->now() + $seconds);
    }

    public function at(int $timestamp): string
    {
        return (new DateTimeImmutable('@' . $timestamp))->setTimezone($this->zone)->format(DATE_ATOM);
    }

    /** Convertit une date ISO 8601 quelconque en date ISO au fuseau de la plateforme ; null si illisible. */
    public function normalize(string $iso): ?string
    {
        try {
            $date = new DateTimeImmutable($iso);
        } catch (\Exception) {
            return null;
        }

        return $date->setTimezone($this->zone)->format(DATE_ATOM);
    }

    /** Secondes écoulées entre deux dates ISO (positif si $to est postérieur). */
    public function secondsBetween(string $from, string $to): int
    {
        return (int) (new DateTimeImmutable($to))->format('U') - (int) (new DateTimeImmutable($from))->format('U');
    }
}
