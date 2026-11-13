<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Infrastructure;

use Scholaris\Modules\Socle\Attendance\Contracts\AttendanceSummary;
use Scholaris\Modules\Socle\Attendance\Contracts\AttendanceSummaryProvider;

/**
 * Repli tant que le module Attendance ne branche pas son implémentation (sa liaison, chargée après la nôtre, la remplace) :
 * aucune absence connue. Évite qu'un contrôleur du conseil de classe ne soit pas constructible.
 */
final class NoAttendanceSummary implements AttendanceSummaryProvider
{
    public function forLearner(string $tenantId, string $establishmentId, string $learnerId, string $from, string $to): AttendanceSummary
    {
        return new AttendanceSummary(0, 0, 0, 0);
    }

    public function absentCountOn(string $tenantId, string $establishmentId, string $date): int
    {
        return 0;
    }
}
