<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Attendance\Contracts;

/**
 * Second indicateur, distinct du seuil de 30 % par EC (T426, decisions.md E1) : taux de PRÉSENCE sur la formation de l'année, condition
 * de présentation à l'examen national BTS/HND (assiduité ≥ 70 %, `higher.national_exam.min_attendance_percent`). Défaut :
 * (séances tenues − absences non justifiées) ÷ séances tenues sur la période. Pour NationalExams.
 */
interface NationalExamAttendance
{
    /** @return array{sessions: int, unjustified: int, presence_percent: float, threshold_percent: float, eligible: bool} */
    public function presenceOf(string $tenantId, string $establishmentId, string $personId, string $from, string $to): array;
}
