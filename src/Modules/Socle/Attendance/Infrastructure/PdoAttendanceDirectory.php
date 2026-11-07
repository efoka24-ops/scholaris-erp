<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Attendance\Infrastructure;

use Scholaris\Modules\Socle\Attendance\Contracts\AttendanceSummary;
use Scholaris\Modules\Socle\Attendance\Contracts\AttendanceSummaryProvider;
use Scholaris\Modules\Socle\Attendance\Contracts\AttendanceTotals;
use Scholaris\Modules\Socle\Messaging\Contracts\AbsenteeDirectory;

/** Contrats publics du module : absents du jour (Messaging) et synthèse d'assiduité (portails, tableaux de bord). Séances validées seulement. */
final class PdoAttendanceDirectory implements AbsenteeDirectory, AttendanceSummaryProvider, AttendanceTotals
{
    public function __construct(private readonly AttendanceRepository $repository)
    {
    }

    public function absentLearnerIds(string $tenantId, string $establishmentId, string $date): array
    {
        return $this->repository->absentOn($tenantId, $establishmentId, $date);
    }

    public function forLearner(string $tenantId, string $establishmentId, string $learnerId, string $from, string $to): AttendanceSummary
    {
        $row = $this->repository->aggregate($tenantId, $establishmentId, $from, $to, $learnerId, 'person')[0] ?? null;

        return new AttendanceSummary($row['sessions'] ?? 0, $row['absences'] ?? 0, $row['unjustified'] ?? 0, $row['lates'] ?? 0);
    }

    public function byLearner(string $tenantId, string $establishmentId, string $from, string $to): array
    {
        $out = [];
        foreach ($this->repository->aggregate($tenantId, $establishmentId, $from, $to, null, 'person') as $row) {
            $out[$row['key']] = new AttendanceSummary($row['sessions'], $row['absences'], $row['unjustified'], $row['lates']);
        }

        return $out;
    }

    public function absentCountOn(string $tenantId, string $establishmentId, string $date): int
    {
        return count($this->repository->absentOn($tenantId, $establishmentId, $date));
    }
}
