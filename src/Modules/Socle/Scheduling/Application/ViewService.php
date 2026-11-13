<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Scheduling\Application;

use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use Scholaris\Modules\Socle\Scheduling\Infrastructure\SchedulingStore;

/** Vues de l'emploi du temps : semaine par classe, par enseignant ou par salle ; consommation des volumes horaires. */
final class ViewService
{
    /** Garde-fou : au plus un an de jours parcourus pour un calcul de volumes. */
    private const MAX_DAYS = 366;

    public function __construct(private readonly ScheduleResolver $resolver, private readonly SchedulingStore $store)
    {
    }

    /**
     * Semaine (lundi à dimanche) contenant la date donnée.
     *
     * @param array{structure_id?: string, teacher_person_id?: string, room_id?: string} $filter une seule clé
     * @return array<string, mixed>
     */
    public function week(string $tenantId, string $establishmentId, string $anyDateOfWeek, array $filter, ?Scope $scope): array
    {
        $monday = (new DateTimeImmutable($anyDateOfWeek))->modify('monday this week');
        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $date = $monday->add(new DateInterval('P' . $i . 'D'))->format('Y-m-d');
            $entries = [];
            foreach ($this->resolver->occurrences($tenantId, $establishmentId, $date) as $occurrence) {
                if ($this->matches($occurrence, $filter, $scope)) {
                    $entries[] = $this->entry($occurrence);
                }
            }
            $days[] = ['date' => $date, 'weekday' => $i + 1, 'entries' => $entries];
        }

        return [
            'week_start' => $monday->format('Y-m-d'),
            'week_end' => $monday->add(new DateInterval('P6D'))->format('Y-m-d'),
            'days' => $days,
        ];
    }

    /**
     * Consommation du volume d'un élément constitutif (lecture seule) : minutes tenues jusqu'à la date donnée
     * (annulations et jours fériés déduits), minutes encore programmées après, et reste à programmer si le volume
     * prévu est connu de l'appelant.
     *
     * @return array<string, mixed>
     */
    public function consumption(string $tenantId, string $establishmentId, string $courseUnitId, string $asOf, ?int $plannedMinutes): array
    {
        $slots = $this->store->where('timetable_slots', $tenantId, $establishmentId, ['course_unit_id' => $courseUnitId]);
        $realized = 0;
        $upcoming = 0;
        /** @var array<string, int> $byKind */
        $byKind = [];
        if ($slots !== []) {
            $from = min(array_map(static fn (array $s): string => (string) $s['valid_from'], $slots));
            $to = $asOf;
            foreach ($slots as $slot) {
                $to = max($to, $slot['valid_to'] === null ? $asOf : (string) $slot['valid_to']);
            }
            $limit = (new DateTimeImmutable($from))->add(new DateInterval('P' . self::MAX_DAYS . 'D'));
            $end = min(new DateTimeImmutable($to), $limit);
            foreach (new DatePeriod(new DateTimeImmutable($from), new DateInterval('P1D'), $end->add(new DateInterval('P1D'))) as $day) {
                $date = $day->format('Y-m-d');
                foreach ($this->resolver->occurrences($tenantId, $establishmentId, $date) as $occurrence) {
                    if ($occurrence->courseUnitId !== $courseUnitId || !$occurrence->isHeld()) {
                        continue;
                    }
                    if ($date <= $asOf) {
                        $realized += $occurrence->minutes();
                        $kind = $occurrence->sessionKind ?? 'other';
                        $byKind[$kind] = ($byKind[$kind] ?? 0) + $occurrence->minutes();
                    } else {
                        $upcoming += $occurrence->minutes();
                    }
                }
            }
        }

        return [
            'course_unit_id' => $courseUnitId,
            'as_of' => $asOf,
            'planned_minutes' => $plannedMinutes,
            'realized_minutes' => $realized,
            'realized_minutes_by_kind' => $byKind,
            'scheduled_upcoming_minutes' => $upcoming,
            'remaining_minutes' => $plannedMinutes === null ? null : max(0, $plannedMinutes - $realized),
            'unscheduled_minutes' => $plannedMinutes === null ? null : max(0, $plannedMinutes - $realized - $upcoming),
        ];
    }

    /** @param array{structure_id?: string, teacher_person_id?: string, room_id?: string} $filter */
    private function matches(Occurrence $occurrence, array $filter, ?Scope $scope): bool
    {
        if ($scope !== null && !$scope->allows($occurrence->teacherPersonId, $occurrence->structureIds, $occurrence->originalTeacherPersonId)) {
            return false;
        }
        if (isset($filter['structure_id']) && !in_array($filter['structure_id'], $occurrence->structureIds, true)) {
            return false;
        }
        if (isset($filter['teacher_person_id']) && $filter['teacher_person_id'] !== $occurrence->teacherPersonId && $filter['teacher_person_id'] !== $occurrence->originalTeacherPersonId) {
            return false;
        }

        return !isset($filter['room_id']) || $filter['room_id'] === $occurrence->roomId;
    }

    /** @return array<string, mixed> */
    private function entry(Occurrence $o): array
    {
        return [
            'slot_id' => $o->slotId,
            'structure_ids' => $o->structureIds,
            'subject_label' => $o->subjectLabel,
            'teacher_person_id' => $o->teacherPersonId,
            'original_teacher_person_id' => $o->originalTeacherPersonId,
            'room_id' => $o->roomId,
            'start_time' => $o->startTime,
            'end_time' => $o->endTime,
            'status' => $o->status,
            'change_id' => $o->changeId,
        ];
    }
}
