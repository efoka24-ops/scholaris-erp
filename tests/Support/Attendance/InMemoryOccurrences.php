<?php

declare(strict_types=1);

namespace Tests\Support\Attendance;

use Scholaris\Modules\Socle\Scheduling\Contracts\CourseUnitOccurrences;
use Scholaris\Modules\Socle\Scheduling\Contracts\OccurrenceInfo;

/** Doublure de CourseUnitOccurrences calculée jour par jour sur la doublure d'emploi du temps (annulations exclues). */
final class InMemoryOccurrences implements CourseUnitOccurrences
{
    public function __construct(private readonly InMemoryTimetable $timetable)
    {
    }

    public function heldOf(string $tenantId, string $establishmentId, string $courseUnitId, string $from, string $to): array
    {
        $out = [];
        for ($day = new \DateTimeImmutable($from); $day->format('Y-m-d') <= $to; $day = $day->modify('+1 day')) {
            $date = $day->format('Y-m-d');
            $bySlot = [];
            foreach ($this->timetable->slotsOn($tenantId, $establishmentId, $date) as $slot) {
                if ($slot->courseUnitId !== $courseUnitId) {
                    continue;
                }
                $minutes = (int) ((strtotime($slot->endTime) - strtotime($slot->startTime)) / 60);
                $structures = isset($bySlot[$slot->id]) ? [...$bySlot[$slot->id]->structureIds, $slot->structureId] : [$slot->structureId];
                $bySlot[$slot->id] = new OccurrenceInfo($slot->id, $date, $slot->startTime, $slot->endTime, $minutes, $slot->teacherPersonId, $structures, $courseUnitId, $slot->sessionKind, OccurrenceInfo::PLANNED);
            }
            array_push($out, ...array_values($bySlot));
        }

        return $out;
    }

    public function scheduledOf(string $tenantId, string $establishmentId, string $courseUnitId, string $from, string $to): array
    {
        return $this->heldOf($tenantId, $establishmentId, $courseUnitId, $from, $to);
    }

    public function heldMinutesByKind(string $tenantId, string $establishmentId, string $courseUnitId, string $from, string $to): array
    {
        $minutes = [];
        foreach ($this->heldOf($tenantId, $establishmentId, $courseUnitId, $from, $to) as $occurrence) {
            $kind = $occurrence->sessionKind ?? 'other';
            $minutes[$kind] = ($minutes[$kind] ?? 0) + $occurrence->minutes;
        }

        return $minutes;
    }
}
