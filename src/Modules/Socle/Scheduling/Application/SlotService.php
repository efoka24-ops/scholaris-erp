<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Scheduling\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Scholaris\Modules\Socle\Scheduling\Contracts\RoomCapacity;
use Scholaris\Modules\Socle\Scheduling\Domain\Conflict;
use Scholaris\Modules\Socle\Scheduling\Domain\ConflictDetector;
use Scholaris\Modules\Socle\Scheduling\Domain\Hhmm;
use Scholaris\Modules\Socle\Scheduling\Domain\SlotPlacement;
use Scholaris\Modules\Socle\Scheduling\Infrastructure\SchedulingStore;

/** Créneaux hebdomadaires : enregistrement avec détection de conflits, lecture cloisonnée. */
final class SlotService
{
    public function __construct(
        private readonly SchedulingStore $store,
        private readonly ConflictDetector $detector,
        private readonly RoomCapacity $rooms,
        private readonly EnrollmentDirectory $enrollments,
        private readonly PersonDirectory $people,
        private readonly SchedulingClock $clock,
    ) {
    }

    /** @return array{0: list<array<string, mixed>>, 1: string|null} */
    public function search(string $tenantId, string $establishmentId, ListQuery $query, ?Scope $scope): array
    {
        $conditions = [];
        foreach (['teacher_person_id', 'room_id', 'weekday', 'period_of_day', 'course_unit_id', 'session_kind'] as $field) {
            if (isset($query->filters[$field])) {
                $conditions[$field] = $field === 'weekday' ? (int) $query->filters[$field] : $query->filters[$field];
            }
        }
        $sql = [];
        $params = [];
        if (isset($query->filters['structure_id'])) {
            $sql[] = 'id IN (SELECT slot_id FROM timetable_slot_structures WHERE tenant_id = ? AND establishment_id = ? AND structure_id = ?)';
            array_push($params, $tenantId, $establishmentId, $query->filters['structure_id']);
        }
        if ($scope !== null) {
            [$clause, $scopeParams] = self::scopeClause($tenantId, $scope, 'id');
            $sql[] = $clause;
            array_push($params, ...$scopeParams);
        }
        [$rows, $next] = $this->store->page('timetable_slots', $tenantId, $establishmentId, $conditions, implode(' AND ', $sql), $params, $query);

        return [$this->withStructures($tenantId, $rows), $next];
    }

    /**
     * Condition SQL « créneaux visibles par ce périmètre ».
     *
     * @return array{0: string, 1: list<string>}
     */
    public static function scopeClause(string $tenantId, Scope $scope, string $slotColumn, string $teacherColumn = 'teacher_person_id'): array
    {
        $params = [$scope->personId];
        $clause = $teacherColumn . ' = ?';
        if ($scope->structureIds !== []) {
            $clause .= sprintf(' OR %s IN (SELECT slot_id FROM timetable_slot_structures WHERE tenant_id = ? AND structure_id IN (%s))', $slotColumn, implode(', ', array_fill(0, count($scope->structureIds), '?')));
            array_push($params, $tenantId, ...$scope->structureIds);
        }

        return ['(' . $clause . ')', $params];
    }

    /** @return array<string, mixed> */
    public function get(string $tenantId, string $establishmentId, string $id, ?Scope $scope = null): array
    {
        $row = $this->store->find('timetable_slots', $tenantId, $establishmentId, $id) ?? throw HttpException::notFound('Créneau introuvable.');
        $slot = $this->withStructures($tenantId, [$row])[0];
        if ($scope !== null && !$scope->allows((string) $slot['teacher_person_id'], $slot['structure_ids'])) {
            throw HttpException::notFound('Créneau introuvable.');
        }

        return $slot;
    }

    /**
     * Vérifie un emplacement sans l'enregistrer.
     *
     * @param array<string, mixed> $data
     * @return list<Conflict>
     */
    public function check(string $tenantId, string $establishmentId, array $data, ?string $ignoreSlotId = null): array
    {
        $this->assertCoherent($tenantId, $data);

        return $this->conflictsFor($tenantId, $establishmentId, $this->placement($ignoreSlotId, $data), $data['room_id'] ?? null);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(string $tenantId, string $establishmentId, array $data, string $userId): array
    {
        $this->assertCoherent($tenantId, $data);
        $conflicts = $this->conflictsFor($tenantId, $establishmentId, $this->placement(null, $data), $data['room_id'] ?? null);
        if ($conflicts !== []) {
            throw new SchedulingConflict($conflicts);
        }
        $id = (string) new Ulid();
        $now = $this->clock->now();
        $this->store->transaction(function () use ($tenantId, $establishmentId, $id, $data, $userId, $now): void {
            $this->store->insert('timetable_slots', [
                'id' => $id,
                'tenant_id' => $tenantId,
                'establishment_id' => $establishmentId,
                'subject_label' => $data['subject_label'],
                'teacher_person_id' => $data['teacher_person_id'],
                'room_id' => $data['room_id'] ?? null,
                'course_unit_id' => $data['course_unit_id'] ?? null,
                'session_kind' => $data['session_kind'] ?? null,
                'weekday' => $data['weekday'],
                'start_time' => $data['start_time'],
                'end_time' => $data['end_time'],
                'valid_from' => $data['valid_from'],
                'valid_to' => $data['valid_to'] ?? null,
                'period_of_day' => Hhmm::periodOfDay((string) $data['start_time']),
                'created_by' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->insertStructures($tenantId, $establishmentId, $id, $data['structure_ids']);
        });

        return $this->get($tenantId, $establishmentId, $id);
    }

    /**
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    public function update(string $tenantId, string $establishmentId, string $id, array $changes): array
    {
        $current = $this->get($tenantId, $establishmentId, $id);
        $data = array_replace([
            'subject_label' => $current['subject_label'],
            'teacher_person_id' => $current['teacher_person_id'],
            'room_id' => $current['room_id'],
            'course_unit_id' => $current['course_unit_id'],
            'session_kind' => $current['session_kind'] ?? null,
            'weekday' => (int) $current['weekday'],
            'start_time' => $current['start_time'],
            'end_time' => $current['end_time'],
            'valid_from' => $current['valid_from'],
            'valid_to' => $current['valid_to'],
            'structure_ids' => $current['structure_ids'],
        ], $changes);
        $this->assertCoherent($tenantId, $data);
        $conflicts = $this->conflictsFor($tenantId, $establishmentId, $this->placement($id, $data), $data['room_id']);
        if ($conflicts !== []) {
            throw new SchedulingConflict($conflicts);
        }
        $this->store->transaction(function () use ($tenantId, $establishmentId, $id, $data): void {
            $this->store->update('timetable_slots', $tenantId, $establishmentId, $id, [
                'subject_label' => $data['subject_label'],
                'teacher_person_id' => $data['teacher_person_id'],
                'room_id' => $data['room_id'],
                'course_unit_id' => $data['course_unit_id'],
                'session_kind' => $data['session_kind'],
                'weekday' => $data['weekday'],
                'start_time' => $data['start_time'],
                'end_time' => $data['end_time'],
                'valid_from' => $data['valid_from'],
                'valid_to' => $data['valid_to'],
                'period_of_day' => Hhmm::periodOfDay((string) $data['start_time']),
                'updated_at' => $this->clock->now(),
            ]);
            $this->store->delete('timetable_slot_structures', $tenantId, $establishmentId, ['slot_id' => $id]);
            $this->insertStructures($tenantId, $establishmentId, $id, $data['structure_ids']);
        });

        return $this->get($tenantId, $establishmentId, $id);
    }

    public function delete(string $tenantId, string $establishmentId, string $id): void
    {
        $this->get($tenantId, $establishmentId, $id);
        $this->store->transaction(function () use ($tenantId, $establishmentId, $id): void {
            $this->store->delete('timetable_changes', $tenantId, $establishmentId, ['slot_id' => $id]);
            $this->store->delete('timetable_slot_structures', $tenantId, $establishmentId, ['slot_id' => $id]);
            $this->store->delete('timetable_slots', $tenantId, $establishmentId, ['id' => $id]);
        });
    }

    /**
     * Conflits d'un emplacement avec les créneaux de l'établissement (capacité de salle comprise).
     *
     * @return list<Conflict>
     */
    public function conflictsFor(string $tenantId, string $establishmentId, SlotPlacement $candidate, ?string $roomId): array
    {
        $rows = $this->store->conflictCandidates($tenantId, $establishmentId, $candidate->weekday, $candidate->startTime, $candidate->endTime, $candidate->validFrom, $candidate->validTo);
        $structures = $this->store->structuresBySlot($tenantId, array_map(static fn (array $r): string => (string) $r['id'], $rows));
        $others = array_map(static fn (array $r): SlotPlacement => new SlotPlacement(
            (string) $r['id'],
            (string) $r['teacher_person_id'],
            $r['room_id'] === null ? null : (string) $r['room_id'],
            $structures[(string) $r['id']] ?? [],
            (int) $r['weekday'],
            (string) $r['start_time'],
            (string) $r['end_time'],
            (string) $r['valid_from'],
            $r['valid_to'] === null ? null : (string) $r['valid_to'],
        ), $rows);

        return $this->detector->detect($candidate, $others, ...$this->capacity($tenantId, $establishmentId, $roomId, $candidate->structureIds));
    }

    /**
     * @param list<string> $structureIds
     * @return array{0: int|null, 1: int|null} capacité de la salle et effectif attendu
     */
    public function capacity(string $tenantId, string $establishmentId, ?string $roomId, array $structureIds): array
    {
        $capacity = $roomId === null ? null : $this->rooms->capacityOf($tenantId, $establishmentId, $roomId);
        if ($capacity === null) {
            return [null, null];
        }
        $persons = [];
        foreach ($structureIds as $structureId) {
            array_push($persons, ...$this->enrollments->personIdsInStructure($tenantId, $structureId));
        }

        return [$capacity, count(array_unique($persons))];
    }

    /** @param array<string, mixed> $data */
    private function placement(?string $id, array $data): SlotPlacement
    {
        return new SlotPlacement(
            $id,
            (string) $data['teacher_person_id'],
            isset($data['room_id']) ? (string) $data['room_id'] : null,
            array_values($data['structure_ids']),
            (int) $data['weekday'],
            (string) $data['start_time'],
            (string) $data['end_time'],
            (string) $data['valid_from'],
            isset($data['valid_to']) ? (string) $data['valid_to'] : null,
        );
    }

    /** @param array<string, mixed> $data */
    private function assertCoherent(string $tenantId, array $data): void
    {
        $errors = [];
        if ($data['end_time'] <= $data['start_time']) {
            $errors['end_time'] = ['L\'heure de fin doit suivre l\'heure de début.'];
        }
        if (isset($data['valid_to']) && $data['valid_to'] < $data['valid_from']) {
            $errors['valid_to'] = ['La fin de validité ne peut pas précéder le début.'];
        }
        if ($this->people->find($tenantId, (string) $data['teacher_person_id']) === null) {
            $errors['teacher_person_id'] = ['Enseignant introuvable.'];
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
    }

    /** @param list<string> $structureIds */
    private function insertStructures(string $tenantId, string $establishmentId, string $slotId, array $structureIds): void
    {
        foreach (array_values(array_unique($structureIds)) as $structureId) {
            $this->store->insert('timetable_slot_structures', [
                'id' => (string) new Ulid(),
                'tenant_id' => $tenantId,
                'establishment_id' => $establishmentId,
                'slot_id' => $slotId,
                'structure_id' => $structureId,
            ]);
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function withStructures(string $tenantId, array $rows): array
    {
        $structures = $this->store->structuresBySlot($tenantId, array_map(static fn (array $r): string => (string) $r['id'], $rows));

        return array_map(static fn (array $r): array => $r + ['structure_ids' => $structures[(string) $r['id']] ?? []], $rows);
    }
}
