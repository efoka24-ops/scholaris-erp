<?php

declare(strict_types=1);

namespace Tests\Support\Reporting;

use Scholaris\Modules\Socle\Scheduling\Contracts\SlotInfo;
use Scholaris\Modules\Socle\Scheduling\Contracts\TimetableDirectory;

/** Doublure de TimetableDirectory : les créneaux ajoutés sont tenus tous les jours. */
final class Timetable implements TimetableDirectory
{
    /** @var list<SlotInfo> */
    private array $slots = [];

    public function add(SlotInfo $slot): void
    {
        $this->slots[] = $slot;
    }

    public function slot(string $tenantId, string $slotId): ?SlotInfo
    {
        foreach ($this->slots as $slot) {
            if ($slot->id === $slotId && $slot->tenantId === $tenantId) {
                return $slot;
            }
        }

        return null;
    }

    public function slotsOn(string $tenantId, string $establishmentId, string $date): array
    {
        return array_values(array_filter($this->slots, static fn (SlotInfo $s): bool => $s->tenantId === $tenantId && $s->establishmentId === $establishmentId));
    }

    public function teacherSlotsOn(string $tenantId, string $teacherPersonId, string $date): array
    {
        return array_values(array_filter($this->slots, static fn (SlotInfo $s): bool => $s->tenantId === $tenantId && $s->teacherPersonId === $teacherPersonId));
    }
}
