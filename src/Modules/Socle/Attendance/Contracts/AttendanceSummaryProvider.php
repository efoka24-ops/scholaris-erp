<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Attendance\Contracts;

/** Assiduité pour les portails et tableaux de bord. Fourni par le module Attendance (S08). */
interface AttendanceSummaryProvider
{
    /** Bornes incluses, format AAAA-MM-JJ. */
    public function forLearner(string $tenantId, string $establishmentId, string $learnerId, string $from, string $to): AttendanceSummary;

    /** Nombre d'absents (toutes absences) ce jour-là dans l'établissement. */
    public function absentCountOn(string $tenantId, string $establishmentId, string $date): int;
}
