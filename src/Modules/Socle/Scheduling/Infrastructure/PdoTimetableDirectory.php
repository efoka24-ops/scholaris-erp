<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Scheduling\Infrastructure;

use Scholaris\Modules\Socle\Scheduling\Application\Occurrence;
use Scholaris\Modules\Socle\Scheduling\Application\ScheduleResolver;
use Scholaris\Modules\Socle\Scheduling\Contracts\SlotInfo;
use Scholaris\Modules\Socle\Scheduling\Contracts\TimetableDirectory;

/** Emplois du temps vus par les autres modules : modifications ponctuelles, jours fériés et fermetures appliqués. */
final class PdoTimetableDirectory implements TimetableDirectory
{
    public function __construct(private readonly SchedulingStore $store, private readonly ScheduleResolver $resolver)
    {
    }

    public function slot(string $tenantId, string $slotId): ?SlotInfo
    {
        $row = $this->store->find('timetable_slots', $tenantId, null, $slotId);
        if ($row === null) {
            return null;
        }
        $structures = $this->store->structuresBySlot($tenantId, [$slotId])[$slotId] ?? [];

        return new SlotInfo(
            (string) $row['id'],
            (string) $row['tenant_id'],
            (string) $row['establishment_id'],
            $structures[0] ?? '',
            (string) $row['teacher_person_id'],
            (string) $row['subject_label'],
            (int) $row['weekday'],
            (string) $row['start_time'],
            (string) $row['end_time'],
            (string) $row['valid_from'],
            $row['valid_to'] === null ? null : (string) $row['valid_to'],
            $row['room_id'] === null ? null : (string) $row['room_id'],
            $row['course_unit_id'] === null ? null : (string) $row['course_unit_id'],
            isset($row['session_kind']) ? (string) $row['session_kind'] : null,
        );
    }

    public function slotsOn(string $tenantId, string $establishmentId, string $date): array
    {
        return $this->infos($this->resolver->occurrences($tenantId, $establishmentId, $date));
    }

    public function teacherSlotsOn(string $tenantId, string $teacherPersonId, string $date): array
    {
        return $this->infos(array_values(array_filter(
            $this->resolver->occurrences($tenantId, null, $date),
            static fn (Occurrence $o): bool => $o->teacherPersonId === $teacherPersonId,
        )));
    }

    /**
     * @param list<Occurrence> $occurrences
     * @return list<SlotInfo>
     */
    private function infos(array $occurrences): array
    {
        $out = [];
        foreach ($occurrences as $occurrence) {
            if ($occurrence->isHeld()) {
                array_push($out, ...$occurrence->toSlotInfos());
            }
        }

        return $out;
    }
}
