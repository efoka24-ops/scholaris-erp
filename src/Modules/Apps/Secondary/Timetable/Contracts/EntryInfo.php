<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Timetable\Contracts;

/** Créneau d'emploi du temps du secondaire (lecture seule), posé au socle Scheduling sous `slotId`. */
final class EntryInfo
{
    public function __construct(
        public readonly string $id,
        public readonly string $slotId,
        public readonly string $assignmentId,
        public readonly string $classId,
        public readonly ?string $groupCode,
        public readonly string $teacherPersonId,
        public readonly ?string $roomId,
        /** 1 = lundi … 7 = dimanche */
        public readonly int $weekday,
        public readonly string $startTime,
        public readonly string $endTime,
        public readonly string $validFrom,
        public readonly ?string $validTo,
    ) {
    }

    /** Le créneau vaut ce jour-là (jour de la semaine et période de validité). */
    public function holdsOn(string $date): bool
    {
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $day !== false && (int) $day->format('N') === $this->weekday && $this->validFrom <= $date && ($this->validTo === null || $date <= $this->validTo);
    }
}
