<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Reporting\Infrastructure\Indicators;

use Scholaris\Modules\Socle\Attendance\Contracts\AttendanceSummaryProvider;
use Scholaris\Modules\Socle\Attendance\Contracts\AttendanceTotals;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\Reporting\Contracts\Indicator;
use Scholaris\Modules\Socle\Reporting\Contracts\IndicatorProvider;
use Scholaris\Modules\Socle\Reporting\Contracts\IndicatorScope;

/**
 * Assiduité du jour et de la semaine, d'après AttendanceSummaryProvider. Lecture groupée par AttendanceTotals quand le
 * fournisseur l'expose (cas d'Attendance), sinon une synthèse par apprenant.
 */
final class AttendanceIndicators implements IndicatorProvider
{
    public function __construct(private readonly AttendanceSummaryProvider $attendance, private readonly EnrollmentDirectory $enrollments)
    {
    }

    public function module(): string
    {
        return 'attendance';
    }

    public function indicators(IndicatorScope $scope): array
    {
        $sessions = 0;
        $absences = 0;
        $unjustified = 0;
        $learners = array_unique($this->enrollments->personIdsInEstablishment($scope->tenantId, $scope->establishmentId));
        $totals = $this->attendance instanceof AttendanceTotals
            ? $this->attendance->byLearner($scope->tenantId, $scope->establishmentId, $scope->weekStart, $scope->today)
            : null;
        foreach ($learners as $learnerId) {
            $summary = $totals === null
                ? $this->attendance->forLearner($scope->tenantId, $scope->establishmentId, $learnerId, $scope->weekStart, $scope->today)
                : ($totals[$learnerId] ?? null);
            if ($summary === null) {
                continue;
            }
            $sessions += $summary->sessions;
            $absences += $summary->absences;
            $unjustified += $summary->unjustifiedAbsences;
        }

        $rate = $sessions === 0
            ? Indicator::unavailable('attendance.week_unjustified_rate', 'Absences non justifiées de la semaine', 'Aucune séance d\'appel cette semaine.', 'percent')
            : Indicator::of('attendance.week_unjustified_rate', 'Absences non justifiées de la semaine', round(100 * $unjustified / $sessions, 1), 'percent', [
                'sessions' => $sessions,
                'absences' => $absences,
                'unjustified_absences' => $unjustified,
            ]);

        return [
            Indicator::of('attendance.absent_today', 'Absents du jour', $this->attendance->absentCountOn($scope->tenantId, $scope->establishmentId, $scope->today)),
            $rate,
        ];
    }
}
