<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Scheduling\Infrastructure;

use Scholaris\Modules\Socle\Scheduling\Contracts\TeacherClasses;

/** Classes d'un enseignant lues dans les créneaux hebdomadaires en vigueur. */
final class PdoTeacherClasses implements TeacherClasses
{
    public function __construct(private readonly SchedulingStore $store)
    {
    }

    public function structureIdsOf(string $tenantId, string $teacherPersonId, string $onDate): array
    {
        $rows = $this->store->rows(
            'SELECT DISTINCT s.structure_id FROM timetable_slot_structures s
             JOIN timetable_slots t ON t.id = s.slot_id AND t.tenant_id = s.tenant_id
             WHERE t.tenant_id = ? AND t.teacher_person_id = ? AND t.valid_from <= ? AND (t.valid_to IS NULL OR t.valid_to >= ?)
             ORDER BY s.structure_id',
            [$tenantId, $teacherPersonId, $onDate, $onDate],
        );

        return array_map(static fn (array $row): string => (string) $row['structure_id'], $rows);
    }
}
