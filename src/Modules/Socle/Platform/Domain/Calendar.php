<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Platform\Domain;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** Calcul sur des jours calendaires AAAA-MM-JJ (sans heure ni fuseau : aucune dérive à l'heure d'été). */
final class Calendar
{
    private function __construct()
    {
    }

    public static function isDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    public static function addDays(string $day, int $days): string
    {
        return self::parse($day)->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
    }

    /** Ajoute des mois en restant dans le mois cible (31 janvier + 1 mois = 28 ou 29 février). */
    public static function addMonths(string $day, int $months): string
    {
        $date = self::parse($day);
        $first = $date->modify('first day of this month')->modify('+' . $months . ' months');
        $dayOfMonth = min((int) $date->format('d'), (int) $first->format('t'));

        return $first->format('Y-m-') . sprintf('%02d', $dayOfMonth);
    }

    /** Nombre de jours de $from à $to (négatif si $to est avant $from). */
    public static function daysBetween(string $from, string $to): int
    {
        $diff = self::parse($from)->diff(self::parse($to));

        return (int) $diff->days * ($diff->invert === 1 ? -1 : 1);
    }

    public static function max(string $a, string $b): string
    {
        return strcmp($a, $b) >= 0 ? $a : $b;
    }

    /** JJ/MM/AAAA pour les messages. */
    public static function display(string $day): string
    {
        return self::parse($day)->format('d/m/Y');
    }

    private static function parse(string $day): DateTimeImmutable
    {
        if (!self::isDate($day)) {
            throw new InvalidArgumentException('Date invalide : ' . $day);
        }

        return new DateTimeImmutable($day . 'T00:00:00', new DateTimeZone('UTC'));
    }
}
