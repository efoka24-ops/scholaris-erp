<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Attendance\Contracts;

/** Synthèse d'assiduité d'un apprenant sur une période. */
final class AttendanceSummary
{
    public function __construct(
        public readonly int $sessions,
        public readonly int $absences,
        public readonly int $unjustifiedAbsences,
        public readonly int $lates,
    ) {
    }

    /** Taux d'absences non justifiées entre 0 et 100 (0 si aucune séance). */
    public function unjustifiedRatePercent(): float
    {
        return $this->sessions === 0 ? 0.0 : round(100 * $this->unjustifiedAbsences / $this->sessions, 1);
    }
}
