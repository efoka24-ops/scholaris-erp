<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Timetable\Infrastructure;

use Scholaris\Modules\Apps\Secondary\Support\Store;
use Scholaris\Modules\Apps\Secondary\Timetable\Contracts\AssignmentInfo;
use Scholaris\Modules\Apps\Secondary\Timetable\Contracts\EntryInfo;
use Scholaris\Modules\Apps\Secondary\Timetable\Contracts\TeachingAssignments;

/** Lecture des affectations et des créneaux du secondaire (tables se_teaching_assignments et se_timetable_entries). */
final class PdoTeachingAssignments implements TeachingAssignments
{
    public const ASSIGNMENTS = 'se_teaching_assignments';
    public const ENTRIES = 'se_timetable_entries';

    public function __construct(private readonly Store $store)
    {
    }

    public function find(string $tenantId, string $establishmentId, string $assignmentId): ?AssignmentInfo
    {
        $row = $this->store->find(self::ASSIGNMENTS, $tenantId, $establishmentId, $assignmentId);

        return $row === null ? null : self::assignment($row);
    }

    public function forTeacher(string $tenantId, string $establishmentId, string $personId, ?string $academicYearId = null): array
    {
        return $this->select($tenantId, $establishmentId, ['teacher_person_id' => $personId] + ($academicYearId === null ? [] : ['academic_year_id' => $academicYearId]));
    }

    public function forClass(string $tenantId, string $establishmentId, string $classId, ?string $academicYearId = null): array
    {
        return $this->select($tenantId, $establishmentId, ['class_id' => $classId] + ($academicYearId === null ? [] : ['academic_year_id' => $academicYearId]));
    }

    public function forYear(string $tenantId, string $establishmentId, string $academicYearId): array
    {
        return $this->select($tenantId, $establishmentId, ['academic_year_id' => $academicYearId]);
    }

    public function entry(string $tenantId, string $establishmentId, string $entryId): ?EntryInfo
    {
        $row = $this->store->find(self::ENTRIES, $tenantId, $establishmentId, $entryId);

        return $row === null || $row['status'] !== 'active' ? null : self::entryInfo($row);
    }

    public function entriesOf(string $tenantId, string $establishmentId, string $assignmentId): array
    {
        $rows = $this->store->select(self::ENTRIES, $tenantId, $establishmentId, ['assignment_id' => $assignmentId, 'status' => 'active'], 'valid_from, weekday, start_time', 2000);
        $entries = [];
        foreach ($rows as $row) {
            $entries[] = self::entryInfo($row);
        }

        return $entries;
    }

    /** @param array<string, mixed> $row */
    public static function assignment(array $row): AssignmentInfo
    {
        return new AssignmentInfo(
            (string) $row['id'],
            (string) $row['academic_year_id'],
            (string) $row['class_id'],
            $row['group_code'] === null ? null : (string) $row['group_code'],
            (string) $row['se_subject_id'],
            (string) $row['subject_id'],
            (string) $row['subject_code'],
            (string) $row['subject_label'],
            (string) $row['teacher_person_id'],
            (string) $row['teacher_kind'],
            (string) $row['room_kind'],
            $row['room_id'] === null ? null : (string) $row['room_id'],
            (string) $row['status'],
        );
    }

    /** @param array<string, mixed> $row */
    public static function entryInfo(array $row): EntryInfo
    {
        return new EntryInfo(
            (string) $row['id'],
            (string) $row['slot_id'],
            (string) $row['assignment_id'],
            (string) $row['class_id'],
            $row['group_code'] === null ? null : (string) $row['group_code'],
            (string) $row['teacher_person_id'],
            $row['room_id'] === null ? null : (string) $row['room_id'],
            (int) $row['weekday'],
            (string) $row['start_time'],
            (string) $row['end_time'],
            (string) $row['valid_from'],
            $row['valid_to'] === null ? null : (string) $row['valid_to'],
        );
    }

    /**
     * @param array<string, scalar|null> $where
     * @return list<AssignmentInfo>
     */
    private function select(string $tenantId, string $establishmentId, array $where): array
    {
        $list = [];
        foreach ($this->store->select(self::ASSIGNMENTS, $tenantId, $establishmentId, $where + ['status' => 'active'], 'class_id, subject_code, group_code, id', 5000) as $row) {
            $list[] = self::assignment($row);
        }

        return $list;
    }
}
