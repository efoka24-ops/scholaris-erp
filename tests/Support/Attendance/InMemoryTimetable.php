<?php

declare(strict_types=1);

namespace Tests\Support\Attendance;

use Scholaris\Modules\Socle\Scheduling\Contracts\SlotInfo;
use Scholaris\Modules\Socle\Scheduling\Contracts\TimetableDirectory;

/** Doublure de TimetableDirectory : créneaux hebdomadaires, annulations et remplacements ponctuels. */
final class InMemoryTimetable implements TimetableDirectory
{
    /** @var array<string, SlotInfo> */
    private array $slots = [];

    /** @var array<string, true> « créneau|date » annulés */
    private array $cancelled = [];

    /** @var array<string, string> « créneau|date » => enseignant remplaçant */
    private array $replacements = [];

    public function add(SlotInfo $slot): void
    {
        $this->slots[$slot->id] = $slot;
    }

    public function cancel(string $slotId, string $date): void
    {
        $this->cancelled[$slotId . '|' . $date] = true;
    }

    public function replace(string $slotId, string $date, string $teacherPersonId): void
    {
        $this->replacements[$slotId . '|' . $date] = $teacherPersonId;
    }

    public function slot(string $tenantId, string $slotId): ?SlotInfo
    {
        $slot = $this->slots[$slotId] ?? null;

        return $slot !== null && $slot->tenantId === $tenantId ? $slot : null;
    }

    public function slotsOn(string $tenantId, string $establishmentId, string $date): array
    {
        $weekday = (int) date('N', (int) strtotime($date));
        $held = [];
        foreach ($this->slots as $slot) {
            if ($slot->tenantId !== $tenantId || $slot->establishmentId !== $establishmentId || $slot->weekday !== $weekday
                || $date < $slot->validFrom || ($slot->validTo !== null && $date > $slot->validTo) || isset($this->cancelled[$slot->id . '|' . $date])) {
                continue;
            }
            $teacher = $this->replacements[$slot->id . '|' . $date] ?? $slot->teacherPersonId;
            $held[] = new SlotInfo($slot->id, $slot->tenantId, $slot->establishmentId, $slot->structureId, $teacher, $slot->subjectLabel, $slot->weekday, $slot->startTime, $slot->endTime, $slot->validFrom, $slot->validTo, $slot->roomId, $slot->courseUnitId, $slot->sessionKind);
        }

        return $held;
    }

    public function teacherSlotsOn(string $tenantId, string $teacherPersonId, string $date): array
    {
        $out = [];
        foreach ($this->slots as $slot) {
            foreach ($this->slotsOn($tenantId, $slot->establishmentId, $date) as $held) {
                if ($held->id === $slot->id && $held->teacherPersonId === $teacherPersonId) {
                    $out[] = $held;
                }
            }
        }

        return $out;
    }
}
