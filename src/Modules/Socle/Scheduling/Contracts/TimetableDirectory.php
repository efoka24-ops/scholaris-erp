<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Scheduling\Contracts;

/** Emplois du temps. Fourni par le module Scheduling (S07), consommé par Attendance, Staff, Reporting. */
interface TimetableDirectory
{
    public function slot(string $tenantId, string $slotId): ?SlotInfo;

    /**
     * Créneaux effectivement tenus ce jour-là (modifications ponctuelles appliquées : annulés exclus, déplacés et remplacés pris en compte).
     *
     * @return list<SlotInfo>
     */
    public function slotsOn(string $tenantId, string $establishmentId, string $date): array;

    /** @return list<SlotInfo> créneaux d'un enseignant ce jour-là, mêmes règles */
    public function teacherSlotsOn(string $tenantId, string $teacherPersonId, string $date): array;
}
