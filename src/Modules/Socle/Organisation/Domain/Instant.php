<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Domain;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** Normalisation d'un instant ISO 8601 en UTC : les comparaisons textuelles SQL restent alors chronologiques. */
final class Instant
{
    /** @throws InvalidArgumentException si la valeur n'est pas une date ISO 8601 */
    public static function utc(string $value): string
    {
        try {
            $date = new DateTimeImmutable($value);
        } catch (\Exception) {
            throw new InvalidArgumentException('Date ISO 8601 invalide.');
        }

        return $date->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
    }
}
