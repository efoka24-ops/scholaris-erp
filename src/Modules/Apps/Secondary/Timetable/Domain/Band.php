<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Timetable\Domain;

/** Plage horaire de cours définie par l'établissement (Q30) : jour 1 (lundi) à 7, heures HH:MM. */
final class Band
{
    public function __construct(
        public readonly int $weekday,
        public readonly string $startTime,
        public readonly string $endTime,
    ) {
    }

    public function key(): string
    {
        return $this->weekday . '|' . $this->startTime;
    }
}
