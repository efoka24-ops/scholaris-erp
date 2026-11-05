<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Timetable\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Secondary\Support\Access;
use Scholaris\Modules\Apps\Secondary\Support\Input;
use Scholaris\Modules\Apps\Secondary\Support\Perimeter;
use Scholaris\Modules\Apps\Secondary\Support\ScopedByClass;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryClock;
use Scholaris\Modules\Apps\Secondary\Support\Store;
use Scholaris\Modules\Apps\Secondary\Timetable\Domain\Overlaps;
use Scholaris\Modules\Apps\Secondary\Timetable\Infrastructure\PdoTeachingAssignments;

/**
 * Emploi du temps posé (T860) : vues par classe, enseignant, salle ou atelier ; pose manuelle d'un créneau (409 en cas de conflit
 * d'enseignant, de salle, d'atelier ou de classe), vérification à blanc, retrait ; contrôle des conflits ; emploi du temps
 * imprimable DOC-07 (classe, enseignant, salle, atelier ; sans signataire ni QR, CDC).
 */
final class TimetableService
{
    public function __construct(
        private readonly Store $store,
        private readonly Access $access,
        private readonly ScopedByClass $scoped,
        private readonly TimetableContext $context,
        private readonly AssignmentService $assignments,
        private readonly EntryWriter $entries,
        private readonly SecondaryClock $clock,
    ) {
    }

    /**
     * @param array{academic_year_id?: string, class_id?: string, teacher_person_id?: string, room_id?: string, on?: string} $filters
     * @return array{entries: list<array<string, mixed>>, filters: array<string, string>}
     */
    public function view(Principal $principal, string $establishmentId, array $filters): array
    {
        $tenantId = $principal->tenantId;
        [$perimeter, $ownPerson] = $this->readPerimeter($principal, $establishmentId);
        [$sql, $params] = $this->scoped->sqlFilter($perimeter);
        if ($ownPerson !== null) {
            $filters['teacher_person_id'] = $ownPerson;
            [$sql, $params] = ['1 = 1', []];
        }
        if (isset($filters['class_id'])) {
            $this->access->assertReadableClass($ownPerson !== null ? new Perimeter($tenantId, $establishmentId, true) : $perimeter, $filters['class_id']);
        }
        $where = ['status' => 'active'] + array_intersect_key($filters, ['academic_year_id' => 1, 'class_id' => 1, 'teacher_person_id' => 1, 'room_id' => 1]);
        $rows = $this->store->select(PdoTeachingAssignments::ENTRIES, $tenantId, $establishmentId, $where, 'weekday, start_time, class_id, id', 5000, $sql, $params);
        $on = $filters['on'] ?? null;
        if ($on !== null) {
            $rows = array_values(array_filter($rows, static fn (array $r): bool => $r['valid_from'] <= $on && ($r['valid_to'] === null || $r['valid_to'] >= $on)));
        }
        $names = $this->context->names($tenantId, array_map(static fn (array $r): string => (string) $r['teacher_person_id'], $rows));
        $classes = [];
        $entries = [];
        foreach ($rows as $row) {
            $classId = (string) $row['class_id'];
            $classes[$classId] ??= $this->context->className($tenantId, $establishmentId, $classId);
            $entries[] = self::entry($row) + ['teacher_name' => $names[(string) $row['teacher_person_id']] ?? '', 'class_label' => $classes[$classId]];
        }

        return ['entries' => $entries, 'filters' => array_map('strval', $filters)];
    }

    /**
     * @param array<string, mixed> $body assignment_id, weekday, start_time, end_time, valid_from, valid_to?, room_id?
     * @return array<string, mixed>
     */
    public function create(Principal $principal, string $establishmentId, array $body): array
    {
        [$assignment, $data] = $this->prepare($principal, $establishmentId, $body);
        $row = $this->entries->pose($principal->tenantId, $establishmentId, $assignment, $data['weekday'], $data['start_time'], $data['end_time'], $data['valid_from'], $data['valid_to'], $data['room_id'], 'manual', $principal->userId);
        $this->access->audit($principal, 'secondary.timetable.entry_created', 'se_timetable_entry', (string) $row['id'], null, $row);

        return self::entry($row);
    }

    /**
     * @param array<string, mixed> $body mêmes champs que la pose
     * @return array{conflicts: list<array{kind: string, message: string}>}
     */
    public function check(Principal $principal, string $establishmentId, array $body): array
    {
        [$assignment, $data] = $this->prepare($principal, $establishmentId, $body);

        return ['conflicts' => $this->entries->conflicts($principal->tenantId, $establishmentId, $assignment, $data['weekday'], $data['start_time'], $data['end_time'], $data['valid_from'], $data['valid_to'], $data['room_id'])];
    }

    public function delete(Principal $principal, string $establishmentId, string $id): void
    {
        $tenantId = $principal->tenantId;
        $perimeter = $this->access->require($principal, $establishmentId, TimetableContext::MANAGE);
        $entry = $this->store->findOrFail(PdoTeachingAssignments::ENTRIES, $tenantId, $establishmentId, $id, 'Créneau introuvable.');
        $this->access->assertWritableClass($principal, $perimeter, (string) $entry['class_id'], 'se_timetable_entry', $id);
        if ($entry['status'] !== 'active') {
            throw HttpException::notFound('Créneau introuvable.');
        }
        $this->entries->remove($tenantId, $establishmentId, $entry);
        $this->access->audit($principal, 'secondary.timetable.entry_removed', 'se_timetable_entry', $id, $entry, null);
    }

    /**
     * Conflits de l'emploi du temps posé dans le périmètre (contrôle après saisie ou génération).
     *
     * @return list<array{kind: string, refs: list<string>, message: string}>
     */
    public function conflicts(Principal $principal, string $establishmentId, ?string $academicYearId): array
    {
        $tenantId = $principal->tenantId;
        $perimeter = $this->access->require($principal, $establishmentId, TimetableContext::READ);
        [$sql, $params] = $this->scoped->sqlFilter($perimeter);
        $where = ['status' => 'active'] + ($academicYearId === null ? [] : ['academic_year_id' => $academicYearId]);
        $rows = $this->store->select(PdoTeachingAssignments::ENTRIES, $tenantId, $establishmentId, $where, 'weekday, start_time, id', 20000, $sql, $params);

        return Overlaps::detect(array_map(static fn (array $row) => EntryWriter::occupation($row), $rows));
    }

    /**
     * DOC-07 : emploi du temps imprimable d'une classe, d'un enseignant, d'une salle ou d'un atelier, en vigueur le jour `on`.
     * Document à la demande, sans numéro, sans signataire ni QR (CDC).
     *
     * @return array<string, mixed>
     */
    public function document(Principal $principal, string $establishmentId, string $view, string $id, string $on): array
    {
        $field = match ($view) {
            'class' => 'class_id',
            'teacher' => 'teacher_person_id',
            'room' => 'room_id',
            default => throw HttpException::validation(['view' => ['Valeurs possibles : class, teacher, room.']]),
        };
        $data = $this->view($principal, $establishmentId, [$field => $id, 'on' => $on]);
        $tenantId = $principal->tenantId;
        $holder = match ($view) {
            'class' => $this->context->className($tenantId, $establishmentId, $id),
            'teacher' => $this->context->names($tenantId, [$id])[$id] ?? $id,
            default => $id,
        };
        $days = [];
        foreach ($data['entries'] as $entry) {
            $weekday = (int) $entry['weekday'];
            $days[$weekday]['weekday'] = $weekday;
            $days[$weekday]['label'] = TimetableContext::DAYS_FR[$weekday] ?? (string) $weekday;
            $days[$weekday]['entries'][] = $entry;
        }
        ksort($days);

        return [
            'doc' => 'DOC-07', 'title' => 'Emploi du temps', 'title_en' => 'Timetable', 'view' => $view, 'holder_id' => $id, 'holder_label' => $holder,
            'on' => $on, 'days' => array_values($days), 'entries_count' => count($data['entries']), 'generated_at' => $this->clock->now(),
            'notice' => 'Document indicatif : les remplacements et permutations sont notifiés.',
        ];
    }

    /**
     * Perimètre de lecture : celui des droits de lecture de l'emploi du temps ; à défaut, l'enseignant (R09) lit le sien seulement.
     *
     * @return array{0: Perimeter, 1: ?string} périmètre, personne de l'enseignant si lecture limitée à son emploi du temps
     */
    public function readPerimeter(Principal $principal, string $establishmentId): array
    {
        $perimeter = $this->access->perimeter($principal, $establishmentId, TimetableContext::READ);
        if (!$perimeter->isEmpty()) {
            return [$perimeter, null];
        }
        $own = $this->access->require($principal, $establishmentId, TimetableContext::OWN);
        $person = $this->context->personOf($principal);
        if ($person === null) {
            throw HttpException::forbidden('Compte sans fiche de personne : emploi du temps personnel indisponible.');
        }

        return [$own, $person];
    }

    /**
     * @param array<string, mixed> $body
     * @return array{0: array<string, mixed>, 1: array{weekday: int, start_time: string, end_time: string, valid_from: string, valid_to: ?string, room_id: ?string}}
     */
    private function prepare(Principal $principal, string $establishmentId, array $body): array
    {
        $tenantId = $principal->tenantId;
        $perimeter = $this->access->require($principal, $establishmentId, TimetableContext::MANAGE);
        $in = new Input($body);
        $assignmentId = (string) $in->reference('assignment_id');
        $weekday = (int) $in->int('weekday', 1, 7);
        $start = (string) TimetableContext::time($in, 'start_time');
        $end = (string) TimetableContext::time($in, 'end_time');
        $validFrom = (string) $in->date('valid_from');
        $validTo = $in->date('valid_to', false, true);
        $roomId = $in->reference('room_id', false, true);
        $in->done();
        if ($end <= $start) {
            throw HttpException::validation(['end_time' => ['L\'heure de fin doit suivre l\'heure de début.']]);
        }
        if ($validTo !== null && $validTo < $validFrom) {
            throw HttpException::validation(['valid_to' => ['La fin de validité doit suivre son début.']]);
        }
        $assignment = $this->store->findOrFail(PdoTeachingAssignments::ASSIGNMENTS, $tenantId, $establishmentId, $assignmentId, 'Affectation introuvable.');
        $this->access->assertWritableClass($principal, $perimeter, (string) $assignment['class_id'], 'se_teaching_assignment', $assignmentId);
        $this->context->opened($tenantId, $establishmentId);
        if ($assignment['status'] !== 'active') {
            throw HttpException::conflict('Affectation terminée.');
        }
        $this->assignments->assertRoomKind($tenantId, $establishmentId, (string) $assignment['room_kind']);
        $roomId ??= $this->defaultRoom($tenantId, $establishmentId, $assignment);

        return [$assignment, ['weekday' => $weekday, 'start_time' => $start, 'end_time' => $end, 'valid_from' => $validFrom, 'valid_to' => $validTo, 'room_id' => $roomId]];
    }

    /** @param array<string, mixed> $assignment */
    public function defaultRoom(string $tenantId, string $establishmentId, array $assignment): ?string
    {
        if ($assignment['room_id'] !== null) {
            return (string) $assignment['room_id'];
        }

        return $assignment['room_kind'] === 'classroom' ? $this->assignments->classRoom($tenantId, $establishmentId, (string) $assignment['academic_year_id'], (string) $assignment['class_id']) : null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function entry(array $row): array
    {
        return [
            'id' => (string) $row['id'], 'academic_year_id' => (string) $row['academic_year_id'], 'slot_id' => (string) $row['slot_id'],
            'assignment_id' => (string) $row['assignment_id'], 'class_id' => (string) $row['class_id'], 'group_code' => $row['group_code'] === null ? null : (string) $row['group_code'],
            'subject_ref' => (string) $row['se_subject_id'], 'subject_label' => (string) $row['subject_label'], 'teacher_person_id' => (string) $row['teacher_person_id'],
            'room_id' => $row['room_id'] === null ? null : (string) $row['room_id'], 'weekday' => (int) $row['weekday'], 'start_time' => (string) $row['start_time'],
            'end_time' => (string) $row['end_time'], 'valid_from' => (string) $row['valid_from'], 'valid_to' => $row['valid_to'] === null ? null : (string) $row['valid_to'],
            'origin' => (string) $row['origin'], 'status' => (string) $row['status'],
        ];
    }
}
