<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Internships\Domain;

/** Créneau d'une soutenance (jour, HH:MM-HH:MM) et chevauchement de deux créneaux du même jour (bornes exclues). */
final class Slot
{
    public const TIME = '/^([01]\d|2[0-3]):[0-5]\d$/';

    private function __construct()
    {
    }

    public static function validTime(string $time): bool
    {
        return preg_match(self::TIME, $time) === 1;
    }

    public static function overlaps(string $start, string $end, string $otherStart, string $otherEnd): bool
    {
        return $start < $otherEnd && $otherStart < $end;
    }
}
