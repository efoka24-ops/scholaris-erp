<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Portals\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\RateLimit\Clock;
use Scholaris\Modules\Socle\Assessment\Contracts\ActorDirectory;
use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentDirectory;
use Scholaris\Modules\Socle\Scheduling\Contracts\SlotInfo;
use Scholaris\Modules\Socle\Scheduling\Contracts\TimetableDirectory;
use Scholaris\Modules\Socle\Staff\Contracts\StaffDirectory;
use Scholaris\Modules\Socle\Staff\Contracts\TeacherWorkload;

/** Portail enseignant : mes classes, mon emploi du temps du jour, mes saisies de notes à faire, mes heures du mois. */
final class TeacherPortalService
{
    public function __construct(
        private readonly ActorDirectory $actors,
        private readonly StaffDirectory $staff,
        private readonly TeacherWorkload $workload,
        private readonly TimetableDirectory $timetable,
        private readonly EstablishmentDirectory $establishments,
        private readonly GradingTodoRegistry $grading,
        private readonly Clock $clock,
    ) {
    }

    /** @return array<string, mixed> */
    public function teacher(string $tenantId, string $userId): array
    {
        $personId = $this->actors->personIdOf($tenantId, $userId);
        if ($personId === null) {
            throw HttpException::forbidden('Votre compte n\'est rattaché à aucune personne : contactez l\'établissement.');
        }
        $info = $this->staff->find($tenantId, $personId);
        if ($info === null || $info->tenantId !== $tenantId || $info->status === 'left') {
            throw HttpException::forbidden('Aucune fiche d\'enseignant n\'est rattachée à votre compte.');
        }

        $today = date('Y-m-d', $this->clock->now());
        $month = substr($today, 0, 7);
        $assignments = $this->workload->assignmentsOf($tenantId, $personId);
        $names = $this->establishmentNames($tenantId, $assignments);

        $classes = array_map(static fn (array $a): array => $a + ['establishment_name' => $names[$a['establishment_id']] ?? null], $assignments);

        $lessons = array_map(static fn (SlotInfo $slot): array => [
            'subject' => $slot->subjectLabel, 'start' => $slot->startTime, 'end' => $slot->endTime,
            'establishment_id' => $slot->establishmentId, 'structure_id' => $slot->structureId, 'room_id' => $slot->roomId,
        ], array_values(array_filter(
            $this->timetable->teacherSlotsOn($tenantId, $personId, $today),
            static fn (SlotInfo $slot): bool => $slot->tenantId === $tenantId,
        )));
        usort($lessons, static fn (array $a, array $b): int => strcmp($a['start'], $b['start']));

        $pending = $this->grading->pendingFor($tenantId, $personId);

        return [
            'person_id' => $personId,
            'kind' => $info->kind,
            'today' => $today,
            'classes' => $classes,
            'timetable_today' => $lessons,
            'grading_todo' => $pending === null
                ? ['available' => false, 'reason' => 'Aucune source ne fournit les saisies de notes à faire.', 'items' => []]
                : ['available' => true, 'reason' => null, 'items' => $pending],
            'hours_month' => $this->hours($tenantId, $personId, $month, $assignments),
        ];
    }

    /**
     * @param list<array{establishment_id: string, structure_id: ?string, subject_label: ?string, session_kind: string}> $assignments
     * @return array<string, int|string>
     */
    private function hours(string $tenantId, string $personId, string $month, array $assignments): array
    {
        $totals = ['declared_minutes' => 0, 'validated_minutes' => 0, 'rejected_minutes' => 0];
        foreach (array_unique(array_column($assignments, 'establishment_id')) as $establishmentId) {
            $hours = $this->workload->hoursOf($tenantId, $establishmentId, $personId, $month);
            foreach (array_keys($totals) as $key) {
                $totals[$key] += $hours[$key];
            }
        }

        return ['month' => $month] + $totals;
    }

    /**
     * @param list<array{establishment_id: string, structure_id: ?string, subject_label: ?string, session_kind: string}> $assignments
     * @return array<string, string>
     */
    private function establishmentNames(string $tenantId, array $assignments): array
    {
        $names = [];
        foreach ($this->establishments->findMany($tenantId, array_values(array_unique(array_column($assignments, 'establishment_id')))) as $establishment) {
            $names[$establishment->id] = $establishment->name;
        }

        return $names;
    }
}
