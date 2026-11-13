<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Attendance\Application;

use Scholaris\Modules\Socle\Attendance\Contracts\AttendanceCodes;
use Scholaris\Modules\Socle\Attendance\Contracts\AttendanceSummary;
use Scholaris\Modules\Socle\Attendance\Contracts\UnitAttendanceSummaryProvider;
use Scholaris\Modules\Socle\Attendance\Infrastructure\AttendanceRepository;
use Scholaris\Modules\Socle\Scheduling\Contracts\CourseUnitOccurrences;
use Scholaris\Modules\Socle\Scheduling\Contracts\OccurrenceInfo;

/** Assiduité par élément constitutif (D19), sur la base choisie par le paramètre attendance.base (D20). */
final class UnitAssiduity implements UnitAttendanceSummaryProvider
{
    public function __construct(
        private readonly AttendanceRepository $repository,
        private readonly AttendanceSettings $settings,
        private readonly CourseUnitOccurrences $occurrences,
    ) {
    }

    public function forLearnerByUnit(string $tenantId, string $establishmentId, string $learnerId, string $courseUnitId, ?string $from = null, ?string $to = null): AttendanceSummary
    {
        return $this->summaries($tenantId, $establishmentId, $learnerId, $courseUnitId, $from, $to)[$learnerId . '|' . $courseUnitId] ?? new AttendanceSummary(0, 0, 0, 0);
    }

    public function learnersAbove(string $tenantId, string $establishmentId, string $courseUnitId, float $thresholdPercent, ?string $from = null, ?string $to = null): array
    {
        $out = [];
        foreach ($this->summaries($tenantId, $establishmentId, null, $courseUnitId, $from, $to) as $key => $summary) {
            if ($summary->unjustifiedRatePercent() > $thresholdPercent) {
                $out[explode('|', $key, 2)[0]] = $summary;
            }
        }

        return $out;
    }

    public function unitsAbove(string $tenantId, string $establishmentId, string $learnerId, float $thresholdPercent, ?string $from = null, ?string $to = null): array
    {
        $out = [];
        foreach ($this->summaries($tenantId, $establishmentId, $learnerId, null, $from, $to) as $key => $summary) {
            if ($summary->unjustifiedRatePercent() > $thresholdPercent) {
                $out[explode('|', $key, 2)[1]] = $summary;
            }
        }

        return $out;
    }

    /**
     * Synthèses calculées sur la base de l'établissement, par « apprenant|EC ».
     *
     * @return array<string, AttendanceSummary>
     */
    public function summaries(string $tenantId, string $establishmentId, ?string $learnerId, ?string $courseUnitId, ?string $from, ?string $to): array
    {
        $planned = $from !== null && $to !== null && $this->settings->base($tenantId, $establishmentId) === AttendanceCodes::BASE_PLANNED;
        /** @var array<string, list<OccurrenceInfo>> $byUnit */
        $byUnit = [];
        $out = [];
        foreach ($this->repository->unitAggregate($tenantId, $establishmentId, $from, $to, $learnerId, $courseUnitId) as $row) {
            $sessions = $row['sessions'];
            if ($planned) {
                $byUnit[$row['unit']] ??= $this->occurrences->heldOf($tenantId, $establishmentId, $row['unit'], (string) $from, (string) $to);
                $programmed = 0;
                foreach ($byUnit[$row['unit']] as $occurrence) {
                    if (array_intersect($occurrence->structureIds, $row['structures']) !== []) {
                        ++$programmed;
                    }
                }
                $sessions = max($sessions, $programmed);
            }
            $out[$row['person'] . '|' . $row['unit']] = new AttendanceSummary($sessions, $row['absences'], $row['unjustified'], $row['lates']);
        }

        return $out;
    }
}
