<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Attendance\Domain;

use DateTimeImmutable;

/** Majorité à 18 ans. Sans date de naissance connue, la personne est traitée comme mineure (le parent garde l'accès). */
final class Adulthood
{
    public static function isAdult(?string $birthDate, string $today): bool
    {
        if ($birthDate === null || $birthDate === '') {
            return false;
        }
        $birth = DateTimeImmutable::createFromFormat('!Y-m-d', $birthDate);
        $now = DateTimeImmutable::createFromFormat('!Y-m-d', $today);

        return $birth !== false && $now !== false && $birth->diff($now)->y >= 18;
    }
}
