<?php

declare(strict_types=1);

namespace Tests\Support\Assessment;

use Scholaris\Modules\Socle\Attendance\Contracts\AttendanceSummary;
use Scholaris\Modules\Socle\Attendance\Contracts\AttendanceSummaryProvider;

/** Doublure d'AttendanceSummaryProvider : absences non justifiées par élève. */
final class FixedAttendance implements AttendanceSummaryProvider
{
    /** @var array<string, int> */
    public array $unjustified = [];

    public function forLearner(string $tenantId, string $establishmentId, string $learnerId, string $from, string $to): AttendanceSummary
    {
        $n = $this->unjustified[$learnerId] ?? 0;

        return new AttendanceSummary(50, $n, $n, 0);
    }

    public function absentCountOn(string $tenantId, string $establishmentId, string $date): int
    {
        return 0;
    }
}
