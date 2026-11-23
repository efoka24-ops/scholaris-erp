<?php

declare(strict_types=1);

namespace Tests\Support\Reporting;

use Scholaris\Modules\Socle\Attendance\Contracts\AttendanceSummary;
use Scholaris\Modules\Socle\Attendance\Contracts\AttendanceSummaryProvider;

/** Doublure d'AttendanceSummaryProvider : synthèse fixe par apprenant, absents du jour par établissement. */
final class Attendance implements AttendanceSummaryProvider
{
    /** @var array<string, AttendanceSummary> */
    private array $summaries = [];

    /** @var array<string, int> */
    private array $absent = [];

    public function set(string $tenantId, string $establishmentId, string $learnerId, AttendanceSummary $summary): void
    {
        $this->summaries[$tenantId . '|' . $establishmentId . '|' . $learnerId] = $summary;
    }

    public function setAbsent(string $tenantId, string $establishmentId, int $count): void
    {
        $this->absent[$tenantId . '|' . $establishmentId] = $count;
    }

    public function forLearner(string $tenantId, string $establishmentId, string $learnerId, string $from, string $to): AttendanceSummary
    {
        return $this->summaries[$tenantId . '|' . $establishmentId . '|' . $learnerId] ?? new AttendanceSummary(0, 0, 0, 0);
    }

    public function absentCountOn(string $tenantId, string $establishmentId, string $date): int
    {
        return $this->absent[$tenantId . '|' . $establishmentId] ?? 0;
    }
}
