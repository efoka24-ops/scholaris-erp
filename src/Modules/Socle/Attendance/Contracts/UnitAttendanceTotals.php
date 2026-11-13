<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Attendance\Contracts;

/**
 * Totaux d'appel par structure (classe, groupe) et par élément constitutif en une lecture (tableaux de bord) : séances validées
 * rattachées à un EC, bornes de dates incluses (AAAA-MM-JJ, nulles = sans limite). Aucune donnée nominative. Fourni par Attendance.
 */
interface UnitAttendanceTotals
{
    /**
     * @return list<array{structure_id: string, course_unit_id: string, label: string, sessions: int, records: int, present: int, late: int, absent: int, unjustified: int}>
     */
    public function byStructureUnit(string $tenantId, string $establishmentId, ?string $from, ?string $to): array;
}
