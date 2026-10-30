<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Domain;

use DateTimeImmutable;

final class Majority
{
    public const AGE = 18;

    /** Sans date de naissance connue, la personne est traitée comme mineure (protection par défaut). */
    public static function isAdult(?string $birthDate, string $today): bool
    {
        if ($birthDate === null || $birthDate === '') {
            return false;
        }
        $birth = DateTimeImmutable::createFromFormat('!Y-m-d', $birthDate);
        $now = DateTimeImmutable::createFromFormat('!Y-m-d', $today);
        if ($birth === false || $now === false) {
            return false;
        }

        return $birth->diff($now)->y >= self::AGE;
    }
}
