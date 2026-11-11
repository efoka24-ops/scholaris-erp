<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Timetable\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\CoefficientBook;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\CoefficientLine;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\Modules;
use Scholaris\Modules\Apps\Secondary\Support\Access;
use Scholaris\Modules\Apps\Secondary\Support\Input;
use Scholaris\Modules\Apps\Secondary\Support\ModuleGate;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryClock;
use Scholaris\Modules\Apps\Secondary\Support\Store;
use Scholaris\Modules\Apps\Secondary\Timetable\Domain\TimetableGenerator;
use Scholaris\Modules\Apps\Secondary\Timetable\Infrastructure\PdoTeachingAssignments;
use Scholaris\Modules\Socle\Staff\Contracts\EstablishmentStaffRoster;

/**
 * Données d'entrée de l'emploi du temps (T860) : affectations (classe ou demi-groupe, matière dont le volume horaire est paramétré
 * pour le niveau, enseignant permanent ou vacataire, nature de salle), plages horaires de l'établissement (Q30), disponibilités des
 * enseignants, salle de chaque classe. Un atelier (salle `workshop`) exige le module `workshops` actif : 409 `module_not_enabled`.
 */
final class AssignmentService
{
    public const TEACHER_KINDS = ['permanent', 'vacataire'];
    public const ROOM_KINDS = ['classroom', 'lab', 'workshop'];
    public const GROUPS = ['G1', 'G2'];
    public const BANDS = 'se_timetable_bands';
    public const AVAILABILITIES = 'se_teacher_availabilities';
    public const CLASS_ROOMS = 'se_timetable_class_rooms';
    private const MAX_BANDS = 120;

    public function __construct(
        private readonly Store $store,
        private readonly Access $access,
        private readonly TimetableContext $context,
        private readonly CoefficientBook $coefficients,
        private readonly ModuleGate $modules,
        private readonly SecondaryClock $clock,
        private readonly EstablishmentStaffRoster $roster,
    ) {
    }

    /**
     * C-118 : enseignants proposés à l'affectation, par nom (jamais d'identifiant à saisir) : personnel actif de l'établissement
     * (socle Staff) et personnes déjà affectées dans l'établissement. Le serveur reste juge de l'affectation.
     *
     * @return list<array{person_id: string, name: string, grade: ?string, teaches: bool, assigned: bool}>
     */
    public function teachers(Principal $principal, string $establishmentId): array
    {
        $tenantId = $principal->tenantId;
        // Aussi les ateliers et les stages (FIX-SEC) : l'enseignant d'une séance pratique se choisit par son nom.
        $this->access->require($principal, $establishmentId, [...TimetableContext::READ, 'secondary.workshops.manage', 'secondary.workshops.session', 'secondary.internships.manage']);
        $candidates = [];
        foreach ($this->roster->membersOf($tenantId, $establishmentId) as $member) {
            $candidates[$member->personId] = ['grade' => $member->grade, 'teaches' => $member->teaches, 'assigned' => false];
        }
        foreach ($this->store->select(PdoTeachingAssignments::ASSIGNMENTS, $tenantId, $establishmentId, [], 'created_at', 5000) as $row) {
            $person = (string) $row['teacher_person_id'];
            $candidates[$person] = ['grade' => $candidates[$person]['grade'] ?? null, 'teaches' => true, 'assigned' => true];
        }
        $names = $this->context->names($tenantId, array_keys($candidates));
        $out = [];
        foreach ($candidates as $personId => $info) {
            $out[] = ['person_id' => (string) $personId, 'name' => $names[$personId] ?? (string) $personId] + $info;
        }
        usort($out, static fn (array $a, array $b): int => [$a['name'], $a['person_id']] <=> [$b['name'], $b['person_id']]);

        return $out;
    }

    /**
     * @param array<string, mixed> $body academic_year_id, class_id, subject_ref, teacher_person_id, teacher_kind, room_kind, group_code?, room_id?
     * @return array<string, mixed>
     */
    public function create(Principal $principal, string $establishmentId, array $body): array
    {
        $tenantId = $principal->tenantId;
        $perimeter = $this->access->require($principal, $establishmentId, TimetableContext::MANAGE);
        $this->context->opened($tenantId, $establishmentId);
        $in = new Input($body);
        $year = (string) $in->reference('academic_year_id');
        $classId = (string) $in->reference('class_id');
        $subjectRef = (string) $in->reference('subject_ref');
        $teacher = (string) $in->reference('teacher_person_id');
        $kind = (string) $in->enum('teacher_kind', self::TEACHER_KINDS);
        $roomKind = (string) $in->enum('room_kind', self::ROOM_KINDS);
        $group = $in->enum('group_code', self::GROUPS, false);
        $roomId = $in->reference('room_id', false, true);
        $in->done();
        $this->access->assertWritableClass($principal, $perimeter, $classId, 'se_teaching_assignment', $classId);
        $this->assertRoomKind($tenantId, $establishmentId, $roomKind);
        $class = $this->context->schoolClass($tenantId, $establishmentId, $classId, $year);
        $line = $this->line($tenantId, $establishmentId, $year, $class->levelCode, $class->streamId, $subjectRef);
        if (!$this->context->personExists($tenantId, $teacher)) {
            throw HttpException::validation(['teacher_person_id' => ['Enseignant introuvable.']]);
        }
        foreach ($this->store->select(PdoTeachingAssignments::ASSIGNMENTS, $tenantId, $establishmentId, ['academic_year_id' => $year, 'class_id' => $classId, 'se_subject_id' => $subjectRef, 'status' => 'active']) as $existing) {
            if ($existing['group_code'] === $group || $existing['group_code'] === null || $group === null) {
                throw HttpException::conflict('Cette matière a déjà un enseignant affecté pour cette classe ou ce demi-groupe : terminez d\'abord l\'affectation en cours.');
            }
        }
        $now = $this->clock->now();
        $row = [
            'id' => (string) new Ulid(), 'academic_year_id' => $year, 'class_id' => $classId, 'group_code' => $group, 'se_subject_id' => $subjectRef,
            'subject_id' => $line->subjectId, 'subject_code' => $line->code, 'subject_label' => $line->label, 'teacher_person_id' => $teacher,
            'teacher_kind' => $kind, 'room_kind' => $roomKind, 'room_id' => $roomId, 'status' => 'active', 'created_by' => $principal->userId,
            'created_at' => $now, 'updated_at' => $now,
        ];
        $this->store->insert(PdoTeachingAssignments::ASSIGNMENTS, $tenantId, $establishmentId, $row);
        $this->access->audit($principal, 'secondary.timetable.assignment_created', 'se_teaching_assignment', $row['id'], null, $row);

        return self::assignment($row, $line->weeklyHours);
    }

    /**
     * @param array<string, mixed> $body teacher_kind?, room_kind?, room_id?, status? (ended)
     * @return array<string, mixed>
     */
    public function update(Principal $principal, string $establishmentId, string $id, array $body): array
    {
        $tenantId = $principal->tenantId;
        $perimeter = $this->access->require($principal, $establishmentId, TimetableContext::MANAGE);
        $row = $this->store->findOrFail(PdoTeachingAssignments::ASSIGNMENTS, $tenantId, $establishmentId, $id, 'Affectation introuvable.');
        $this->access->assertWritableClass($principal, $perimeter, (string) $row['class_id'], 'se_teaching_assignment', $id);
        $this->context->opened($tenantId, $establishmentId);
        $in = new Input($body, true);
        $in->enum('teacher_kind', self::TEACHER_KINDS, false);
        $in->enum('room_kind', self::ROOM_KINDS, false);
        $in->reference('room_id', false, true);
        $in->enum('status', ['ended'], false);
        $changes = $in->done();
        if (isset($changes['room_kind'])) {
            $this->assertRoomKind($tenantId, $establishmentId, (string) $changes['room_kind']);
        }
        if ($row['status'] !== 'active') {
            throw HttpException::conflict('Affectation terminée : elle ne se modifie plus.');
        }
        $changes['updated_at'] = $this->clock->now();
        $this->store->update(PdoTeachingAssignments::ASSIGNMENTS, $tenantId, $establishmentId, $changes, ['id' => $id]);
        $this->access->audit($principal, 'secondary.timetable.assignment_updated', 'se_teaching_assignment', $id, $row, $changes);
        $updated = $this->store->findOrFail(PdoTeachingAssignments::ASSIGNMENTS, $tenantId, $establishmentId, $id);

        return self::assignment($updated, null);
    }

    /** @return array{academic_year_id: string, bands: list<array<string, mixed>>} */
    public function bands(Principal $principal, string $establishmentId, string $academicYearId): array
    {
        $this->access->require($principal, $establishmentId, [...TimetableContext::READ, ...TimetableContext::OWN]);

        return ['academic_year_id' => $academicYearId, 'bands' => array_map(static fn (array $row): array => self::band($row), $this->bandRows($principal->tenantId, $establishmentId, $academicYearId))];
    }

    /** @return list<array<string, mixed>> */
    public function bandRows(string $tenantId, string $establishmentId, string $academicYearId): array
    {
        return $this->store->select(self::BANDS, $tenantId, $establishmentId, ['academic_year_id' => $academicYearId], 'weekday, start_time', self::MAX_BANDS);
    }

    /**
     * Remplace les plages horaires de l'année (aucune durée codée : chaque établissement fixe ses heures, Q30).
     *
     * @param array<string, mixed> $body academic_year_id, bands: [{weekday, start_time, end_time, label?}]
     * @return array{academic_year_id: string, bands: list<array<string, mixed>>}
     */
    public function setBands(Principal $principal, string $establishmentId, array $body): array
    {
        $tenantId = $principal->tenantId;
        $perimeter = $this->access->require($principal, $establishmentId, TimetableContext::MANAGE);
        $this->access->assertWhole($principal, $perimeter, 'se_timetable_band');
        $this->context->opened($tenantId, $establishmentId);
        $in = new Input($body);
        $year = (string) $in->reference('academic_year_id');
        $bands = (array) $in->custom('bands', static fn (mixed $v): array => self::parseWindows($v, false));
        $in->done();
        $before = $this->bandRows($tenantId, $establishmentId, $year);
        $now = $this->clock->now();
        $this->store->transaction(function () use ($tenantId, $establishmentId, $year, $bands, $principal, $now): void {
            $this->store->delete(self::BANDS, $tenantId, $establishmentId, ['academic_year_id' => $year]);
            foreach ($bands as $band) {
                $this->store->insert(self::BANDS, $tenantId, $establishmentId, [
                    'id' => (string) new Ulid(), 'academic_year_id' => $year, 'weekday' => $band['weekday'], 'start_time' => $band['start_time'],
                    'end_time' => $band['end_time'], 'label' => $band['label'], 'created_by' => $principal->userId, 'created_at' => $now,
                ]);
            }
        });
        $this->access->audit($principal, 'secondary.timetable.bands_set', 'se_timetable_band', $year, ['count' => count($before)], ['count' => count($bands)]);

        return ['academic_year_id' => $year, 'bands' => array_map(static fn (array $row): array => self::band($row), $this->bandRows($tenantId, $establishmentId, $year))];
    }

    /** @return array{academic_year_id: string, teacher_person_id: string, windows: list<array<string, mixed>>} */
    public function availabilities(Principal $principal, string $establishmentId, string $academicYearId, string $teacherId): array
    {
        $this->access->require($principal, $establishmentId, TimetableContext::READ);

        return ['academic_year_id' => $academicYearId, 'teacher_person_id' => $teacherId, 'windows' => $this->windowRows($principal->tenantId, $establishmentId, $academicYearId, $teacherId)];
    }

    /**
     * Remplace les disponibilités d'un enseignant pour l'année : plages « unavailable » toujours exclues ; un vacataire qui déclare
     * des plages « available » n'est placé que dedans.
     *
     * @param array<string, mixed> $body academic_year_id, teacher_person_id, windows: [{weekday, start_time, end_time, availability_kind}]
     * @return array{academic_year_id: string, teacher_person_id: string, windows: list<array<string, mixed>>}
     */
    public function setAvailabilities(Principal $principal, string $establishmentId, array $body): array
    {
        $tenantId = $principal->tenantId;
        $perimeter = $this->access->require($principal, $establishmentId, TimetableContext::MANAGE);
        $this->access->assertWhole($principal, $perimeter, 'se_teacher_availability');
        $this->context->opened($tenantId, $establishmentId);
        $in = new Input($body);
        $year = (string) $in->reference('academic_year_id');
        $teacher = (string) $in->reference('teacher_person_id');
        $windows = (array) $in->custom('windows', static fn (mixed $v): array => self::parseWindows($v, true));
        $in->done();
        if (!$this->context->personExists($tenantId, $teacher)) {
            throw HttpException::validation(['teacher_person_id' => ['Enseignant introuvable.']]);
        }
        $now = $this->clock->now();
        $this->store->transaction(function () use ($tenantId, $establishmentId, $year, $teacher, $windows, $principal, $now): void {
            $this->store->delete(self::AVAILABILITIES, $tenantId, $establishmentId, ['academic_year_id' => $year, 'teacher_person_id' => $teacher]);
            foreach ($windows as $window) {
                $this->store->insert(self::AVAILABILITIES, $tenantId, $establishmentId, [
                    'id' => (string) new Ulid(), 'academic_year_id' => $year, 'teacher_person_id' => $teacher, 'weekday' => $window['weekday'],
                    'start_time' => $window['start_time'], 'end_time' => $window['end_time'], 'availability_kind' => $window['availability_kind'],
                    'created_by' => $principal->userId, 'created_at' => $now,
                ]);
            }
        });
        $this->access->audit($principal, 'secondary.timetable.availabilities_set', 'se_teacher_availability', $teacher, null, ['academic_year_id' => $year, 'count' => count($windows)]);

        return ['academic_year_id' => $year, 'teacher_person_id' => $teacher, 'windows' => $this->windowRows($tenantId, $establishmentId, $year, $teacher)];
    }

    /** @return list<array{weekday: int, start_time: string, end_time: string, availability_kind: string}> */
    public function windowRows(string $tenantId, string $establishmentId, string $academicYearId, string $teacherId): array
    {
        $rows = [];
        foreach ($this->store->select(self::AVAILABILITIES, $tenantId, $establishmentId, ['academic_year_id' => $academicYearId, 'teacher_person_id' => $teacherId], 'weekday, start_time', 500) as $row) {
            $rows[] = ['weekday' => (int) $row['weekday'], 'start_time' => (string) $row['start_time'], 'end_time' => (string) $row['end_time'], 'availability_kind' => (string) $row['availability_kind']];
        }

        return $rows;
    }

    /**
     * Salle de la classe pour l'année (cours en salle `classroom` sans salle propre à l'affectation).
     *
     * @param array<string, mixed> $body academic_year_id, class_id, room_id
     * @return array<string, mixed>
     */
    public function setClassRoom(Principal $principal, string $establishmentId, array $body): array
    {
        $tenantId = $principal->tenantId;
        $perimeter = $this->access->require($principal, $establishmentId, TimetableContext::MANAGE);
        $in = new Input($body);
        $year = (string) $in->reference('academic_year_id');
        $classId = (string) $in->reference('class_id');
        $roomId = (string) $in->reference('room_id');
        $in->done();
        $this->access->assertWritableClass($principal, $perimeter, $classId, 'se_timetable_class_room', $classId);
        $this->context->opened($tenantId, $establishmentId);
        $this->context->schoolClass($tenantId, $establishmentId, $classId, $year);
        $values = ['room_id' => $roomId, 'updated_by' => $principal->userId, 'updated_at' => $this->clock->now()];
        $existing = $this->store->select(self::CLASS_ROOMS, $tenantId, $establishmentId, ['academic_year_id' => $year, 'class_id' => $classId], 'id', 1)[0] ?? null;
        if ($existing === null) {
            $this->store->insert(self::CLASS_ROOMS, $tenantId, $establishmentId, ['id' => (string) new Ulid(), 'academic_year_id' => $year, 'class_id' => $classId] + $values);
        } else {
            $this->store->update(self::CLASS_ROOMS, $tenantId, $establishmentId, $values, ['id' => (string) $existing['id']]);
        }
        $this->access->audit($principal, 'secondary.timetable.class_room_set', 'se_timetable_class_room', $classId, $existing, $values);

        return ['academic_year_id' => $year, 'class_id' => $classId, 'room_id' => $roomId];
    }

    public function classRoom(string $tenantId, string $establishmentId, string $academicYearId, string $classId): ?string
    {
        $row = $this->store->select(self::CLASS_ROOMS, $tenantId, $establishmentId, ['academic_year_id' => $academicYearId, 'class_id' => $classId], 'id', 1)[0] ?? null;

        return $row === null ? null : (string) $row['room_id'];
    }

    /** Volume horaire hebdomadaire en vigueur de la matière pour la classe (Setup), null si la ligne n'existe plus. */
    public function weeklyHours(string $tenantId, string $establishmentId, string $academicYearId, string $levelCode, ?string $streamId, string $subjectRef): ?float
    {
        foreach ($this->coefficients->forLevel($tenantId, $establishmentId, $academicYearId, $levelCode, $streamId) as $line) {
            if ($line->localSubjectId === $subjectRef) {
                return $line->weeklyHours;
            }
        }

        return null;
    }

    public function assertRoomKind(string $tenantId, string $establishmentId, string $roomKind): void
    {
        if ($roomKind === 'workshop') {
            $this->modules->assertAvailable($tenantId, $establishmentId, Modules::WORKSHOPS, true);
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function assignment(array $row, ?float $weeklyHours): array
    {
        return [
            'id' => (string) $row['id'], 'academic_year_id' => (string) $row['academic_year_id'], 'class_id' => (string) $row['class_id'],
            'group_code' => $row['group_code'] === null ? null : (string) $row['group_code'], 'subject_ref' => (string) $row['se_subject_id'],
            'subject_id' => (string) $row['subject_id'], 'subject_code' => (string) $row['subject_code'], 'subject_label' => (string) $row['subject_label'],
            'teacher_person_id' => (string) $row['teacher_person_id'], 'teacher_kind' => (string) $row['teacher_kind'], 'room_kind' => (string) $row['room_kind'],
            'room_id' => $row['room_id'] === null ? null : (string) $row['room_id'], 'status' => (string) $row['status'], 'weekly_hours' => $weeklyHours,
            'created_at' => (string) $row['created_at'], 'updated_at' => (string) $row['updated_at'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function band(array $row): array
    {
        return ['id' => (string) $row['id'], 'weekday' => (int) $row['weekday'], 'start_time' => (string) $row['start_time'], 'end_time' => (string) $row['end_time'], 'label' => $row['label'] === null ? null : (string) $row['label']];
    }

    private function line(string $tenantId, string $establishmentId, string $year, string $levelCode, ?string $streamId, string $subjectRef): CoefficientLine
    {
        foreach ($this->coefficients->forLevel($tenantId, $establishmentId, $year, $levelCode, $streamId) as $line) {
            if ($line->localSubjectId === $subjectRef) {
                return $line;
            }
        }

        throw HttpException::validation(['subject_ref' => ['Aucun volume horaire paramétré pour cette matière à ce niveau (paramétrage, coefficients).']]);
    }

    /**
     * Liste de plages : jour 1 à 7, heures HH:MM, fin après début, aucun chevauchement le même jour.
     *
     * @return list<array{weekday: int, start_time: string, end_time: string, label: ?string, availability_kind: string}>
     */
    private static function parseWindows(mixed $value, bool $withKind): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > self::MAX_BANDS) {
            throw new \InvalidArgumentException(sprintf('Liste de %d plages au plus attendue.', self::MAX_BANDS));
        }
        $parsed = [];
        foreach ($value as $index => $item) {
            $position = (int) $index + 1;
            if (!is_array($item)) {
                throw new \InvalidArgumentException(sprintf('Plage %d : objet attendu.', $position));
            }
            $weekday = $item['weekday'] ?? null;
            $start = $item['start_time'] ?? null;
            $end = $item['end_time'] ?? null;
            if (!is_int($weekday) || $weekday < 1 || $weekday > 7) {
                throw new \InvalidArgumentException(sprintf('Plage %d : jour de 1 (lundi) à 7 attendu.', $position));
            }
            foreach ([$start, $end] as $time) {
                if (!is_string($time) || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) !== 1) {
                    throw new \InvalidArgumentException(sprintf('Plage %d : heures HH:MM attendues.', $position));
                }
            }
            if ($end <= $start) {
                throw new \InvalidArgumentException(sprintf('Plage %d : la fin doit suivre le début.', $position));
            }
            $kind = $item['availability_kind'] ?? TimetableGenerator::UNAVAILABLE;
            if ($withKind && !in_array($kind, [TimetableGenerator::AVAILABLE, TimetableGenerator::UNAVAILABLE], true)) {
                throw new \InvalidArgumentException(sprintf('Plage %d : availability_kind available ou unavailable.', $position));
            }
            $label = $item['label'] ?? null;
            if ($label !== null && (!is_string($label) || mb_strlen($label) > 60)) {
                throw new \InvalidArgumentException(sprintf('Plage %d : libellé de 60 caractères au plus.', $position));
            }
            foreach ($parsed as $other) {
                if ($other['weekday'] === $weekday && $other['availability_kind'] === $kind && $other['start_time'] < $end && $start < $other['end_time']) {
                    throw new \InvalidArgumentException(sprintf('Plage %d : chevauche une autre plage du même jour.', $position));
                }
            }
            $parsed[] = ['weekday' => $weekday, 'start_time' => $start, 'end_time' => $end, 'label' => $label, 'availability_kind' => (string) $kind];
        }

        return $parsed;
    }
}
