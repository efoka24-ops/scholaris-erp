<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Attendance\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Attendance\Domain\AssiduityLevel;
use Scholaris\Modules\Socle\Attendance\Infrastructure\AttendanceRepository;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/**
 * Cumuls d'assiduité par apprenant et par matière, et liste des apprenants non autorisés à composer.
 * Seules les séances validées comptent ; une absence justifiée ne compte pas dans le non justifié.
 */
final class AssiduityService
{
    public function __construct(
        private readonly AttendanceRepository $repository,
        private readonly AttendanceSettings $settings,
        private readonly EnrollmentDirectory $enrollments,
        private readonly PersonDirectory $people,
        private readonly UnitAssiduity $units,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(string $tenantId, string $establishmentId, string $learnerId, ?string $from, ?string $to): array
    {
        // A2 : un identifiant inconnu de ce client (autre client compris) répond 404, jamais une synthèse vide.
        $person = $this->people->find($tenantId, $learnerId);
        if ($person === null || $person->tenantId !== $tenantId) {
            throw HttpException::notFound('Apprenant introuvable.');
        }
        [$max, $alert] = $this->thresholds($tenantId, $establishmentId);
        $total = $this->repository->aggregate($tenantId, $establishmentId, $from, $to, $learnerId, 'person')[0] ?? ['sessions' => 0, 'absences' => 0, 'unjustified' => 0, 'lates' => 0];
        $bySubject = [];
        foreach ($this->repository->aggregate($tenantId, $establishmentId, $from, $to, $learnerId, 'subject') as $row) {
            $bySubject[] = ['subject' => $row['key']] + $this->figures($row, $max, $alert);
        }

        $byUnit = [];
        foreach ($this->units->summaries($tenantId, $establishmentId, $learnerId, null, $from, $to) as $key => $unit) {
            $byUnit[] = ['course_unit_id' => explode('|', $key, 2)[1]] + $this->figures(
                ['sessions' => $unit->sessions, 'absences' => $unit->absences, 'unjustified' => $unit->unjustifiedAbsences, 'lates' => $unit->lates],
                $max,
                $alert,
            );
        }

        return ['person_id' => $learnerId, 'from' => $from, 'to' => $to] + $this->figures($total, $max, $alert)
            + ['thresholds' => ['max_unjustified_percent' => $max, 'alert_unjustified_percent' => $alert], 'by_subject' => $bySubject,
                'base' => $this->settings->base($tenantId, $establishmentId), 'by_course_unit' => $byUnit];
    }

    /**
     * Apprenants au-dessus du seuil (non autorisés à composer) ou à partir du seuil d'alerte.
     *
     * @return array{data: list<array<string, mixed>>, thresholds: array<string, float>}
     */
    public function notAllowedToSit(string $tenantId, string $establishmentId, ?string $from, ?string $to, ?string $structureId): array
    {
        [$max, $alert] = $this->thresholds($tenantId, $establishmentId);
        $scope = $structureId === null ? null : array_flip($this->enrollments->personIdsInStructure($tenantId, $structureId));

        $rows = [];
        foreach ($this->repository->aggregate($tenantId, $establishmentId, $from, $to, null, 'person') as $row) {
            if ($scope !== null && !isset($scope[$row['key']])) {
                continue;
            }
            $figures = $this->figures($row, $max, $alert);
            if ($figures['level'] !== AssiduityLevel::Ok->value) {
                $rows[] = ['person_id' => $row['key']] + $figures;
            }
        }
        usort($rows, static fn (array $a, array $b): int => [$b['unjustified_rate_percent'], $a['person_id']] <=> [$a['unjustified_rate_percent'], $b['person_id']]);

        $names = [];
        foreach ($this->people->findMany($tenantId, array_map(static fn (array $r): string => (string) $r['person_id'], $rows)) as $person) {
            $names[$person->id] = $person->fullName();
        }
        foreach ($rows as &$row) {
            $row['full_name'] = $names[$row['person_id']] ?? null;
        }
        unset($row);

        return ['data' => $rows, 'thresholds' => ['max_unjustified_percent' => $max, 'alert_unjustified_percent' => $alert]];
    }

    /** @return array{0: float, 1: float} */
    private function thresholds(string $tenantId, string $establishmentId): array
    {
        return [$this->settings->maxUnjustifiedPercent($tenantId, $establishmentId), $this->settings->alertPercent($tenantId, $establishmentId)];
    }

    /**
     * @param array{sessions: int, absences: int, unjustified: int, lates: int}|array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function figures(array $row, float $max, float $alert): array
    {
        $sessions = (int) $row['sessions'];
        $unjustified = (int) $row['unjustified'];
        $rate = $sessions === 0 ? 0.0 : round(100 * $unjustified / $sessions, 1);

        return [
            'sessions' => $sessions,
            'absences' => (int) $row['absences'],
            'unjustified_absences' => $unjustified,
            'justified_absences' => (int) $row['absences'] - $unjustified,
            'lates' => (int) $row['lates'],
            'unjustified_rate_percent' => $rate,
            'attendance_rate_percent' => $sessions === 0 ? 100.0 : round(100 * ($sessions - (int) $row['absences']) / $sessions, 1),
            'level' => AssiduityLevel::of($rate, $max, $alert)->value,
        ];
    }
}
