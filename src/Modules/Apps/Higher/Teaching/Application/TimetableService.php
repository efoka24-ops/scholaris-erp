<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Teaching\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Higher\Enrolment\Contracts\StudentDirectory;
use Scholaris\Modules\Apps\Higher\Notifications\Contracts\HigherNotifier;
use Scholaris\Modules\Apps\Higher\Support\Perimeter;
use Scholaris\Modules\Apps\Higher\Teaching\Domain\TimetableOverlaps;
use Scholaris\Modules\Apps\Higher\Teaching\Infrastructure\TeachingStore;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;
use Scholaris\Modules\Socle\Scheduling\Contracts\SlotDraft;
use Scholaris\Modules\Socle\Scheduling\Contracts\TimetableConflict;
use Scholaris\Modules\Socle\Scheduling\Contracts\TimetableWriter;

/**
 * Emploi du temps de l'IPES (EX-EDT-01 à 05) : un créneau se pose à partir d'une affectation (l'enseignant doit être affecté à l'EC et
 * au groupe), pour un ou plusieurs groupes (cours mutualisé), de jour, le soir ou le samedi ; conflits enseignant, salle, groupe (socle,
 * TimetableWriter) et capacité (salles de l'IPES) ; vues par groupe, enseignant et salle ; reports et remplacements notifiés (NOT-05).
 */
final class TimetableService
{
    public const ENTRIES = 'hi_timetable_entries';
    public const CHANGES = 'hi_timetable_changes';
    public const CHANGE_KINDS = ['postponed', 'cancelled', 'replaced', 'room_changed'];
    private const CHANGE_LABELS = ['postponed' => 'cours reporté', 'cancelled' => 'cours annulé', 'replaced' => 'enseignant remplacé', 'room_changed' => 'changement de salle'];

    public function __construct(
        private readonly TeachingStore $store,
        private readonly TeachingAccess $access,
        private readonly ElementResolver $elements,
        private readonly TimetableWriter $writer,
        private readonly StudentDirectory $students,
        private readonly HigherNotifier $notifier,
        private readonly SettingsReader $settings,
        private readonly TeachingClock $clock,
    ) {
    }

    /**
     * @param array{assignment_id: string, weekday: int, start_time: string, end_time: string, valid_from: string, valid_to: ?string, room_id: ?string, extra_group_ids: list<string>} $data
     * @return array{conflicts: list<array{kind: string, message: string}>, headcount: int, room_capacity: ?int}
     */
    public function check(Principal $principal, string $establishmentId, array $data): array
    {
        [$assignment, $groups, $headcount, $capacity] = $this->prepare($principal, $establishmentId, $data);
        $conflicts = $this->capacityConflicts($headcount, $capacity);
        foreach ($this->writer->check($principal->tenantId, $establishmentId, $this->draft($assignment, $groups, $data)) as $conflict) {
            $conflicts[] = ['kind' => self::kind($conflict['kind']), 'message' => $conflict['message']];
        }

        return ['conflicts' => $conflicts, 'headcount' => $headcount, 'room_capacity' => $capacity];
    }

    /**
     * @param array{assignment_id: string, weekday: int, start_time: string, end_time: string, valid_from: string, valid_to: ?string, room_id: ?string, extra_group_ids: list<string>} $data
     * @return array<string, mixed>
     */
    public function create(Principal $principal, string $establishmentId, array $data): array
    {
        $tenantId = $principal->tenantId;
        [$assignment, $groups, $headcount, $capacity] = $this->prepare($principal, $establishmentId, $data);
        self::refuse($this->capacityConflicts($headcount, $capacity));
        try {
            $slotId = $this->writer->create($tenantId, $establishmentId, $this->draft($assignment, $groups, $data), $principal->userId);
        } catch (TimetableConflict $e) {
            self::refuse(array_map(static fn (array $c): array => ['kind' => self::kind($c['kind']), 'message' => $c['message']], $e->conflicts));
            throw $e;
        }
        $now = $this->clock->now();
        $row = [
            'id' => (string) new Ulid(), 'slot_id' => $slotId, 'assignment_id' => $assignment['id'], 'school_id' => $assignment['school_id'],
            'department_id' => $assignment['department_id'], 'program_id' => $assignment['program_id'], 'level_id' => $assignment['level_id'],
            'group_id' => $assignment['group_id'], 'group_refs' => json_encode($groups, JSON_THROW_ON_ERROR), 'element_id' => $assignment['element_id'],
            'element_title' => $assignment['element_title'], 'person_id' => $assignment['person_id'], 'room_id' => $data['room_id'],
            'weekday' => $data['weekday'], 'start_time' => $data['start_time'], 'end_time' => $data['end_time'],
            'day_period' => TimetableOverlaps::dayPeriod($data['start_time'], $this->eveningFrom($tenantId, $establishmentId)),
            'session_kind' => $assignment['session_kind'], 'valid_from' => $data['valid_from'], 'valid_to' => $data['valid_to'], 'headcount' => $headcount,
            'created_by' => $principal->userId, 'created_at' => $now, 'updated_at' => $now,
        ];
        $this->store->insert(self::ENTRIES, $tenantId, $establishmentId, $row);
        $this->access->audit($tenantId, $principal->userId, 'higher.timetable.entry_created', 'hi_timetable_entry', $row['id'], null, $row);

        return $this->store->findOrFail(self::ENTRIES, $tenantId, $establishmentId, $row['id']);
    }

    public function delete(Principal $principal, string $establishmentId, string $id): void
    {
        $tenantId = $principal->tenantId;
        $entry = $this->writableEntry($principal, $establishmentId, $id);
        $this->writer->delete($tenantId, $establishmentId, (string) $entry['slot_id']);
        $this->store->delete(self::ENTRIES, $tenantId, $establishmentId, ['id' => $id]);
        $this->access->audit($tenantId, $principal->userId, 'higher.timetable.entry_deleted', 'hi_timetable_entry', $id, $entry, null);
    }

    /**
     * Vue de l'emploi du temps (EX-EDT-01) par groupe, enseignant ou salle, avec les modifications ponctuelles de la semaine.
     *
     * @param array{group_id?: string, person_id?: string, room_id?: string, week?: string} $filters
     * @return array{entries: list<array<string, mixed>>, changes: list<array<string, mixed>>}
     */
    public function view(Principal $principal, string $establishmentId, array $filters, ?string $school): array
    {
        $tenantId = $principal->tenantId;
        $me = $this->access->personOf($principal);
        $ownView = isset($filters['person_id']) && $me === $filters['person_id'] && $this->access->has($principal, $establishmentId, TeachingPermissions::LOG_WRITE);
        $perimeter = $ownView ? new Perimeter($tenantId, $establishmentId, true) : $this->access->require($principal, $establishmentId, TeachingPermissions::TIMETABLE_READ, $school);
        [$sql, $params] = $this->access->sqlFilter($perimeter);
        $where = array_intersect_key($filters, ['person_id' => 1, 'room_id' => 1]);
        $rows = $this->store->select(self::ENTRIES, $tenantId, $establishmentId, $where, 'weekday, start_time, id', 2000, $sql, $params);
        $week = $filters['week'] ?? null;
        $weekEnd = $week === null ? null : (new \DateTimeImmutable($week))->modify('+6 days')->format('Y-m-d');
        $entries = [];
        foreach ($rows as $row) {
            $groups = self::groups($row);
            if (isset($filters['group_id']) && !in_array($filters['group_id'], $groups, true)) {
                continue;
            }
            if ($week !== null && ($row['valid_from'] > $weekEnd || ($row['valid_to'] !== null && $row['valid_to'] < $week))) {
                continue;
            }
            $entries[] = $row + ['groups' => $groups];
        }
        $changes = [];
        if ($entries !== []) {
            $ids = array_column($entries, 'id');
            foreach ($this->store->select(self::CHANGES, $tenantId, $establishmentId, [], 'occurs_on, id', 2000) as $change) {
                if (in_array($change['entry_id'], $ids, true) && ($week === null || ($change['occurs_on'] >= $week && $change['occurs_on'] <= $weekEnd))) {
                    $changes[] = $change;
                }
            }
        }

        return ['entries' => $entries, 'changes' => $changes];
    }

    /**
     * Conflits de l'emploi du temps posé dans le périmètre (vue « conflits »).
     *
     * @return list<array{kind: string, entry_ids: list<string>, message: string}>
     */
    public function conflicts(Principal $principal, string $establishmentId, ?string $school): array
    {
        $tenantId = $principal->tenantId;
        $perimeter = $this->access->require($principal, $establishmentId, TeachingPermissions::TIMETABLE_READ, $school);
        [$sql, $params] = $this->access->sqlFilter($perimeter);
        $rooms = [];
        foreach ($this->store->select(TeacherService::ROOMS, $tenantId, $establishmentId, [], 'id', 5000) as $room) {
            $rooms[(string) $room['id']] = (int) $room['capacity'];
        }
        $entries = [];
        foreach ($this->store->select(self::ENTRIES, $tenantId, $establishmentId, [], 'weekday, start_time, id', 5000, $sql, $params) as $row) {
            $entries[] = [
                'id' => (string) $row['id'], 'weekday' => (int) $row['weekday'], 'start_time' => (string) $row['start_time'], 'end_time' => (string) $row['end_time'],
                'valid_from' => (string) $row['valid_from'], 'valid_to' => $row['valid_to'] === null ? null : (string) $row['valid_to'],
                'person_id' => (string) $row['person_id'], 'room_id' => $row['room_id'] === null ? null : (string) $row['room_id'], 'groups' => self::groups($row),
                'headcount' => (int) $row['headcount'], 'room_capacity' => $row['room_id'] === null ? null : ($rooms[(string) $row['room_id']] ?? null),
            ];
        }

        return TimetableOverlaps::detect($entries);
    }

    /**
     * Report, annulation, remplacement ou changement de salle d'une séance (EX-EDT-05), notifié aux étudiants des groupes et aux
     * enseignants concernés (NOT-05).
     *
     * @param array{entry_id: string, change_kind: string, occurs_on: string, new_date: ?string, new_start_time: ?string, new_end_time: ?string, replacement_person_id: ?string, new_room_id: ?string, reason: string} $data
     * @return array<string, mixed>
     */
    public function change(Principal $principal, string $establishmentId, array $data): array
    {
        $tenantId = $principal->tenantId;
        $entry = $this->writableEntry($principal, $establishmentId, $data['entry_id']);
        $kind = $data['change_kind'];
        $errors = [];
        if ($kind === 'postponed' && ($data['new_date'] === null || $data['new_start_time'] === null || $data['new_end_time'] === null)) {
            $errors['new_date'] = ['Un report exige la nouvelle date et les nouvelles heures.'];
        }
        if ($kind === 'replaced' && $data['replacement_person_id'] === null) {
            $errors['replacement_person_id'] = ['Enseignant remplaçant attendu.'];
        }
        if ($kind === 'room_changed' && $data['new_room_id'] === null) {
            $errors['new_room_id'] = ['Nouvelle salle attendue.'];
        }
        if ((int) (new \DateTimeImmutable($data['occurs_on']))->format('N') !== (int) $entry['weekday']) {
            $errors['occurs_on'] = ['La date ne correspond pas au jour du créneau.'];
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        $row = [
            'id' => (string) new Ulid(), 'entry_id' => $entry['id'], 'school_id' => $entry['school_id'], 'department_id' => $entry['department_id'],
            'program_id' => $entry['program_id'], 'level_id' => $entry['level_id'], 'group_id' => $entry['group_id'], 'change_kind' => $kind,
            'occurs_on' => $data['occurs_on'], 'new_date' => $data['new_date'], 'new_start_time' => $data['new_start_time'], 'new_end_time' => $data['new_end_time'],
            'replacement_person_id' => $data['replacement_person_id'], 'new_room_id' => $data['new_room_id'], 'reason' => $data['reason'],
            'notified_count' => 0, 'created_by' => $principal->userId, 'created_at' => $this->clock->now(),
        ];
        $this->store->insert(self::CHANGES, $tenantId, $establishmentId, $row);
        $row['notified_count'] = $this->notifyChange($tenantId, $establishmentId, $entry, $row);
        $this->store->update(self::CHANGES, $tenantId, $establishmentId, ['notified_count' => $row['notified_count']], ['id' => $row['id']]);
        $this->access->audit($tenantId, $principal->userId, 'higher.timetable.changed', 'hi_timetable_entry', (string) $entry['id'], null, $row);

        return $row;
    }

    /** @return array<string, mixed> */
    public function readableEntry(Principal $principal, string $establishmentId, string $id): array
    {
        $entry = $this->store->findOrFail(self::ENTRIES, $principal->tenantId, $establishmentId, $id, 'Créneau introuvable.');
        $this->access->assertReadable($this->access->require($principal, $establishmentId, TeachingPermissions::TIMETABLE_READ), $entry);

        return $entry + ['groups' => self::groups($entry)];
    }

    /**
     * @param array<string, mixed> $row
     * @return list<string>
     */
    public static function groups(array $row): array
    {
        $groups = json_decode((string) ($row['group_refs'] ?? '[]'), true);

        return is_array($groups) ? array_values(array_map('strval', $groups)) : [];
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $change
     */
    private function notifyChange(string $tenantId, string $establishmentId, array $entry, array $change): int
    {
        $assignment = $this->store->find(AssignmentService::TABLE, $tenantId, $establishmentId, (string) $entry['assignment_id']);
        $year = (string) ($assignment['academic_year_id'] ?? '');
        $recipients = [(string) $entry['person_id'] => true];
        if ($change['replacement_person_id'] !== null) {
            $recipients[(string) $change['replacement_person_id']] = true;
        }
        foreach (self::groups($entry) as $groupId) {
            foreach ($this->students->enrolledIn($tenantId, $establishmentId, $groupId, $year) as $enrolment) {
                $recipients[$enrolment->personId] = true;
            }
        }
        $date = (string) ($change['new_date'] ?? $change['occurs_on']);
        $time = (string) ($change['new_start_time'] ?? $entry['start_time']) . '-' . (string) ($change['new_end_time'] ?? $entry['end_time']);
        $sent = 0;
        foreach (array_keys($recipients) as $personId) {
            $this->notifier->notify($tenantId, $establishmentId, 'NOT-05', (string) $personId, [
                'change_label' => self::CHANGE_LABELS[(string) $change['change_kind']] ?? (string) $change['change_kind'],
                'subject' => (string) $entry['element_title'], 'date' => $date, 'time' => $time,
            ], 'not05:' . $change['id'] . ':' . $personId);
            ++$sent;
        }

        return $sent;
    }

    /**
     * @param array{assignment_id: string, weekday: int, start_time: string, end_time: string, valid_from: string, valid_to: ?string, room_id: ?string, extra_group_ids: list<string>} $data
     * @return array{0: array<string, mixed>, 1: list<string>, 2: int, 3: ?int}
     */
    private function prepare(Principal $principal, string $establishmentId, array $data): array
    {
        $tenantId = $principal->tenantId;
        $assignment = $this->store->findOrFail(AssignmentService::TABLE, $tenantId, $establishmentId, $data['assignment_id'], 'Affectation introuvable.');
        if ($assignment['status'] !== 'active') {
            throw HttpException::validation(['assignment_id' => ['Affectation terminée : l\'enseignant n\'est plus affecté à cet EC.']]);
        }
        if (TimetableOverlaps::minutes($data['end_time']) <= TimetableOverlaps::minutes($data['start_time'])) {
            throw HttpException::validation(['end_time' => ['L\'heure de fin doit suivre l\'heure de début.']]);
        }
        $perimeter = $this->access->require($principal, $establishmentId, TeachingPermissions::TIMETABLE_MANAGE);
        $this->access->assertWritable($principal, $perimeter, $assignment, 'hi_assignment', (string) $assignment['id']);
        $groups = [(string) $assignment['group_id']];
        $headcount = $this->headcount($tenantId, $establishmentId, (string) $assignment['group_id'], (string) $assignment['academic_year_id']);
        foreach ($data['extra_group_ids'] as $groupId) {
            if (in_array($groupId, $groups, true)) {
                continue;
            }
            $place = $this->elements->group($tenantId, $establishmentId, $groupId);
            $this->access->assertWritable($principal, $perimeter, $place, 'hi_group', $groupId);
            $groups[] = $groupId;
            $headcount += $this->headcount($tenantId, $establishmentId, $groupId, (string) $assignment['academic_year_id']);
        }
        $capacity = null;
        if ($data['room_id'] !== null) {
            $room = $this->store->find(TeacherService::ROOMS, $tenantId, $establishmentId, $data['room_id']);
            if ($room === null || (int) $room['active'] !== 1) {
                throw HttpException::validation(['room_id' => ['Salle introuvable ou fermée.']]);
            }
            $capacity = (int) $room['capacity'];
        }

        return [$assignment, $groups, $headcount, $capacity];
    }

    private function headcount(string $tenantId, string $establishmentId, string $groupId, string $academicYearId): int
    {
        $enrolled = count($this->students->enrolledIn($tenantId, $establishmentId, $groupId, $academicYearId));
        if ($enrolled > 0) {
            return $enrolled;
        }

        return (int) ($this->elements->group($tenantId, $establishmentId, $groupId)['capacity'] ?? 0);
    }

    /** @return list<array{kind: string, message: string}> */
    private function capacityConflicts(int $headcount, ?int $capacity): array
    {
        if ($capacity === null || $headcount <= $capacity) {
            return [];
        }

        return [['kind' => TimetableOverlaps::CAPACITY, 'message' => sprintf('La salle (%d places) ne peut pas accueillir %d étudiants.', $capacity, $headcount)]];
    }

    /**
     * @param array<string, mixed> $assignment
     * @param list<string> $groups
     * @param array{weekday: int, start_time: string, end_time: string, valid_from: string, valid_to: ?string, room_id: ?string} $data
     */
    private function draft(array $assignment, array $groups, array $data): SlotDraft
    {
        return new SlotDraft(
            mb_substr((string) $assignment['element_code'] . ' ' . (string) $assignment['element_title'], 0, 120),
            (string) $assignment['person_id'],
            $groups,
            $data['weekday'],
            $data['start_time'],
            $data['end_time'],
            $data['valid_from'],
            $data['valid_to'],
            $data['room_id'],
            (string) $assignment['element_id'],
            (string) $assignment['session_kind'],
        );
    }

    /** @param list<array{kind: string, message: string}> $conflicts */
    private static function refuse(array $conflicts): void
    {
        if ($conflicts === []) {
            return;
        }
        $errors = [];
        foreach ($conflicts as $conflict) {
            $errors[$conflict['kind']][] = $conflict['message'];
        }

        throw new HttpException(409, 'Conflit d\'emploi du temps', $conflicts[0]['message'], 'https://api.scholaris.cm/errors/timetable-conflict', $errors);
    }

    /** Nature de conflit du socle vers celle de l'IPES (structure = groupe). */
    private static function kind(string $socleKind): string
    {
        return match ($socleKind) {
            'structure' => TimetableOverlaps::GROUP,
            default => $socleKind,
        };
    }

    private function eveningFrom(string $tenantId, string $establishmentId): string
    {
        $value = $this->settings->value($tenantId, $establishmentId, TeachingPermissions::EVENING_FROM, '17:00');

        return is_string($value) && preg_match('/^\d{2}:\d{2}$/', $value) === 1 ? $value : '17:00';
    }

    /** @return array<string, mixed> */
    private function writableEntry(Principal $principal, string $establishmentId, string $id): array
    {
        $entry = $this->store->findOrFail(self::ENTRIES, $principal->tenantId, $establishmentId, $id, 'Créneau introuvable.');
        $perimeter = $this->access->require($principal, $establishmentId, TeachingPermissions::TIMETABLE_MANAGE);
        $this->access->assertWritable($principal, $perimeter, $entry, 'hi_timetable_entry', $id);

        return $entry;
    }
}
