<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Scheduling\Domain;

/** Heures « HH:MM » (fuseau Africa/Douala) : la comparaison lexicographique suit l'ordre chronologique. */
final class Hhmm
{
    /** À partir de cette heure, un créneau est un cours du soir. */
    public const EVENING_FROM = '17:00';

    public static function isValid(string $time): bool
    {
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) === 1;
    }

    public static function minutes(string $time): int
    {
        return (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
    }

    public static function duration(string $start, string $end): int
    {
        return self::minutes($end) - self::minutes($start);
    }

    /** « day » (plage du jour) ou « evening » (plage du soir). */
    public static function periodOfDay(string $start): string
    {
        return $start >= self::EVENING_FROM ? 'evening' : 'day';
    }
}
