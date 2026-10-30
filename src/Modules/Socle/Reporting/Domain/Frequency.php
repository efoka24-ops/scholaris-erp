<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Reporting\Domain;

use DateInterval;
use DateTimeImmutable;

/** Périodicité d'un rapport : chaque jour, chaque semaine (jour 1 = lundi à 7) ou chaque mois (jour 1 à 28). */
enum Frequency: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $frequency): string => $frequency->value, self::cases());
    }

    /** Vrai si le jour d'exécution est valide pour cette périodicité. */
    public function acceptsDay(?int $day): bool
    {
        return match ($this) {
            self::Daily => $day === null,
            self::Weekly => $day !== null && $day >= 1 && $day <= 7,
            self::Monthly => $day !== null && $day >= 1 && $day <= 28,
        };
    }

    /** Première échéance strictement postérieure à $from (AAAA-MM-JJ). */
    public function nextAfter(string $from, ?int $day): string
    {
        $date = new DateTimeImmutable($from);
        $one = new DateInterval('P1D');
        for ($i = 0; $i < 400; ++$i) {
            $date = $date->add($one);
            $matches = match ($this) {
                self::Daily => true,
                self::Weekly => (int) $date->format('N') === $day,
                self::Monthly => (int) $date->format('j') === $day,
            };
            if ($matches) {
                return $date->format('Y-m-d');
            }
        }

        return $date->format('Y-m-d');
    }
}
