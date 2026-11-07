<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Timetable\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryClock;
use Scholaris\Modules\Apps\Secondary\Support\Store;
use Scholaris\Modules\Apps\Secondary\Timetable\Domain\Occupation;
use Scholaris\Modules\Apps\Secondary\Timetable\Domain\Overlaps;
use Scholaris\Modules\Apps\Secondary\Timetable\Infrastructure\PdoTeachingAssignments;
use Scholaris\Modules\Socle\Organisation\Contracts\StructureDraft;
use Scholaris\Modules\Socle\Organisation\Contracts\StructureWriter;
use Scholaris\Modules\Socle\Scheduling\Contracts\SlotDraft;
use Scholaris\Modules\Socle\Scheduling\Contracts\TimetableConflict;
use Scholaris\Modules\Socle\Scheduling\Contracts\TimetableDirectory;
use Scholaris\Modules\Socle\Scheduling\Contracts\TimetableWriter;

/**
 * Pose, raccourcit et retire les créneaux du secondaire : contrôle propre au secondaire (classe entière contre demi-groupe,
 * Overlaps), PUIS écriture au socle par TimetableWriter (conflits d'enseignant, de salle et de structure du socle, autres
 * applications comprises). Tout conflit donne 409 `timetable-conflict` avec la liste des conflits ; rien n'est écrit.
 * Un demi-groupe (dédoublement) est une structure `group` du socle, fille de la classe, créée à la première pose.
 */
final class EntryWriter
{
    public const CONFLICT_TYPE = 'https://api.scholaris.cm/errors/timetable-conflict';
    public const GROUPS = 'se_timetable_groups';

    public function __construct(
        private readonly Store $store,
        private readonly TimetableWriter $writer,
        private readonly StructureWriter $structures,
        private readonly SecondaryClock $clock,
        private readonly TimetableDirectory $directory,
    ) {
    }

    /**
     * Créneaux actifs de l'établissement, en occupations (contrôle des conflits).
     *
     * @param list<string> $excludeIds
     * @return list<Occupation>
     */
    public function occupations(string $tenantId, string $establishmentId, array $excludeIds = []): array
    {
        $list = [];
        foreach ($this->store->select(PdoTeachingAssignments::ENTRIES, $tenantId, $establishmentId, ['status' => 'active'], 'weekday, start_time, id', 20000) as $row) {
            if (!in_array((string) $row['id'], $excludeIds, true)) {
                $list[] = self::occupation($row);
            }
        }

        return $list;
    }

    /** @param array<string, mixed> $row ligne se_timetable_entries */
    public static function occupation(array $row): Occupation
    {
        return new Occupation(
            (string) $row['id'],
            (int) $row['weekday'],
            (string) $row['start_time'],
            (string) $row['end_time'],
            (string) $row['teacher_person_id'],
            (string) $row['class_id'],
            $row['group_code'] === null ? null : (string) $row['group_code'],
            $row['room_id'] === null ? null : (string) $row['room_id'],
            (string) $row['valid_from'],
            $row['valid_to'] === null ? null : (string) $row['valid_to'],
            (string) $row['subject_label'],
        );
    }

    /**
     * Conflits d'une pose envisagée (secondaire puis socle), sans rien écrire.
     *
     * @param array<string, mixed> $assignment ligne se_teaching_assignments
     * @param list<string> $ignoreEntryIds
     * @return list<array{kind: string, message: string}>
     */
    public function conflicts(string $tenantId, string $establishmentId, array $assignment, int $weekday, string $start, string $end, string $validFrom, ?string $validTo, ?string $roomId, ?string $teacherId = null, array $ignoreEntryIds = []): array
    {
        $teacher = $teacherId ?? (string) $assignment['teacher_person_id'];
        $candidate = new Occupation('candidate', $weekday, $start, $end, $teacher, (string) $assignment['class_id'], $assignment['group_code'] === null ? null : (string) $assignment['group_code'], $roomId, $validFrom, $validTo);
        $found = [];
        foreach (Overlaps::conflicts($candidate, $this->occupations($tenantId, $establishmentId, $ignoreEntryIds)) as $conflict) {
            $found[] = ['kind' => $conflict['kind'], 'message' => $conflict['message']];
        }
        array_push($found, ...$this->elsewhere($tenantId, $establishmentId, $teacher, $weekday, $start, $end, $validFrom));
        if ($found !== []) {
            return $found;
        }
        $structure = $this->structureFor($tenantId, $establishmentId, $assignment, false);
        if ($structure === null) {
            return [];
        }
        $draft = new SlotDraft(self::slotLabel($assignment), $teacher, [$structure], $weekday, $start, $end, $validFrom, $validTo, $roomId);
        $ignore = count($ignoreEntryIds) === 1 ? $this->slotOf($tenantId, $establishmentId, $ignoreEntryIds[0]) : null;
        foreach ($this->writer->check($tenantId, $establishmentId, $draft, $ignore) as $conflict) {
            $found[] = ['kind' => self::kind($conflict['kind']), 'message' => $conflict['message']];
        }

        return $found;
    }

    /**
     * Pose un créneau (409 si conflit) et rend la ligne créée.
     *
     * @param array<string, mixed> $assignment ligne se_teaching_assignments
     * @return array<string, mixed>
     */
    public function pose(string $tenantId, string $establishmentId, array $assignment, int $weekday, string $start, string $end, string $validFrom, ?string $validTo, ?string $roomId, string $origin, string $actorId, ?string $runId = null, ?string $parentEntryId = null, ?string $teacherId = null): array
    {
        $teacher = $teacherId ?? (string) $assignment['teacher_person_id'];
        $own = new Occupation('candidate', $weekday, $start, $end, $teacher, (string) $assignment['class_id'], $assignment['group_code'] === null ? null : (string) $assignment['group_code'], $roomId, $validFrom, $validTo);
        $own = array_map(static fn (array $c): array => ['kind' => $c['kind'], 'message' => $c['message']], Overlaps::conflicts($own, $this->occupations($tenantId, $establishmentId)));
        self::refuse([...$own, ...$this->elsewhere($tenantId, $establishmentId, $teacher, $weekday, $start, $end, $validFrom)]);
        $structure = (string) $this->structureFor($tenantId, $establishmentId, $assignment, true);
        $draft = new SlotDraft(self::slotLabel($assignment), $teacher, [$structure], $weekday, $start, $end, $validFrom, $validTo, $roomId);
        try {
            $slotId = $this->writer->create($tenantId, $establishmentId, $draft, $actorId);
        } catch (TimetableConflict $e) {
            self::refuse(array_map(static fn (array $c): array => ['kind' => self::kind($c['kind']), 'message' => $c['message']], $e->conflicts));
            throw $e;
        }
        $now = $this->clock->now();
        $row = [
            'id' => (string) new Ulid(), 'academic_year_id' => (string) $assignment['academic_year_id'], 'slot_id' => $slotId, 'assignment_id' => (string) $assignment['id'],
            'class_id' => (string) $assignment['class_id'], 'group_code' => $assignment['group_code'], 'structure_id' => $structure,
            'se_subject_id' => (string) $assignment['se_subject_id'], 'subject_label' => (string) $assignment['subject_label'], 'teacher_person_id' => $teacher,
            'room_id' => $roomId, 'weekday' => $weekday, 'start_time' => $start, 'end_time' => $end, 'valid_from' => $validFrom, 'valid_to' => $validTo,
            'origin' => $origin, 'run_id' => $runId, 'parent_entry_id' => $parentEntryId, 'status' => 'active', 'created_by' => $actorId,
            'created_at' => $now, 'updated_at' => $now,
        ];
        $this->store->insert(PdoTeachingAssignments::ENTRIES, $tenantId, $establishmentId, $row);

        return $row;
    }

    /**
     * Fin de validité d'un créneau la veille de `$date` (remplacement, permutation) : le passé reste tel qu'il a été tenu.
     *
     * @param array<string, mixed> $entry
     */
    public function endBefore(string $tenantId, string $establishmentId, array $entry, string $date): void
    {
        $validTo = TimetableContext::dayBefore($date);
        $this->writer->update($tenantId, $establishmentId, (string) $entry['slot_id'], ['valid_to' => $validTo]);
        $this->store->update(PdoTeachingAssignments::ENTRIES, $tenantId, $establishmentId, ['valid_to' => $validTo, 'updated_at' => $this->clock->now()], ['id' => (string) $entry['id']]);
    }

    /** @param array<string, mixed> $entry */
    public function remove(string $tenantId, string $establishmentId, array $entry): void
    {
        $this->writer->delete($tenantId, $establishmentId, (string) $entry['slot_id']);
        $this->store->update(PdoTeachingAssignments::ENTRIES, $tenantId, $establishmentId, ['status' => 'removed', 'updated_at' => $this->clock->now()], ['id' => (string) $entry['id']]);
    }

    /** @param list<array{kind: string, message: string}> $conflicts */
    public static function refuse(array $conflicts): void
    {
        if ($conflicts === []) {
            return;
        }
        $errors = [];
        foreach ($conflicts as $conflict) {
            $errors[$conflict['kind']][] = $conflict['message'];
        }

        throw new HttpException(409, 'Conflit d\'emploi du temps', $conflicts[0]['message'], self::CONFLICT_TYPE, $errors, extensions: ['code' => 'timetable_conflict', 'conflict_count' => count($conflicts)]);
    }

    /**
     * Structure posée au socle : la classe, ou le demi-groupe (créé au besoin quand `$create`).
     *
     * @param array<string, mixed> $assignment
     */
    private function structureFor(string $tenantId, string $establishmentId, array $assignment, bool $create): ?string
    {
        $classId = (string) $assignment['class_id'];
        $group = $assignment['group_code'] === null ? null : (string) $assignment['group_code'];
        if ($group === null) {
            return $classId;
        }
        $row = $this->store->select(self::GROUPS, $tenantId, $establishmentId, ['class_id' => $classId, 'group_code' => $group], 'id', 1)[0] ?? null;
        if ($row !== null) {
            return (string) $row['structure_id'];
        }
        if (!$create) {
            return null;
        }
        $structure = $this->structures->createStructure($tenantId, $establishmentId, new StructureDraft(StructureDraft::GROUP, $group, null, $classId));
        $this->store->insert(self::GROUPS, $tenantId, $establishmentId, ['id' => (string) new Ulid(), 'class_id' => $classId, 'group_code' => $group, 'structure_id' => $structure, 'created_at' => $this->clock->now()]);

        return $structure;
    }

    /**
     * L'enseignant a-t-il cours dans un AUTRE établissement du client au même moment (premier jour concerné de la période) ? Le
     * socle ne contrôle les conflits que dans l'établissement de la pose ; un vacataire partage souvent son temps.
     *
     * @return list<array{kind: string, message: string}>
     */
    private function elsewhere(string $tenantId, string $establishmentId, string $teacher, int $weekday, string $start, string $end, string $validFrom): array
    {
        $found = [];
        foreach ($this->directory->teacherSlotsOn($tenantId, $teacher, TimetableContext::firstWeekday($validFrom, $weekday)) as $slot) {
            if ($slot->establishmentId !== $establishmentId && $slot->startTime < $end && $start < $slot->endTime) {
                $found[] = ['kind' => 'teacher', 'message' => sprintf('L\'enseignant a cours dans un autre établissement à ce moment (%s-%s %s).', $slot->startTime, $slot->endTime, $slot->subjectLabel)];
            }
        }

        return $found;
    }

    private function slotOf(string $tenantId, string $establishmentId, string $entryId): ?string
    {
        $row = $this->store->find(PdoTeachingAssignments::ENTRIES, $tenantId, $establishmentId, $entryId);

        return $row === null ? null : (string) $row['slot_id'];
    }

    /** @param array<string, mixed> $assignment */
    private static function slotLabel(array $assignment): string
    {
        $label = (string) $assignment['subject_label'] . ($assignment['group_code'] === null ? '' : ' (' . (string) $assignment['group_code'] . ')');

        return mb_substr($label, 0, 120);
    }

    private static function kind(string $socleKind): string
    {
        return match ($socleKind) {
            'structure' => 'class',
            default => $socleKind,
        };
    }
}
