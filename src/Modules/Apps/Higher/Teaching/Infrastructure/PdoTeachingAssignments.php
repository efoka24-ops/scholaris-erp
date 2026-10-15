<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Teaching\Infrastructure;

use Scholaris\Modules\Apps\Higher\Teaching\Contracts\TeachingAssignmentInfo;
use Scholaris\Modules\Apps\Higher\Teaching\Contracts\TeachingAssignments;

/** Lecture des affectations (hi_assignments) pour les autres sous-modules Higher. */
final class PdoTeachingAssignments implements TeachingAssignments
{
    private const TABLE = 'hi_assignments';

    public function __construct(private readonly TeachingStore $store)
    {
    }

    public function find(string $tenantId, string $establishmentId, string $assignmentId): ?TeachingAssignmentInfo
    {
        $row = $this->store->find(self::TABLE, $tenantId, $establishmentId, $assignmentId);

        return $row === null ? null : self::info($row);
    }

    public function forTeacher(string $tenantId, string $establishmentId, string $personId, ?string $academicYearId = null): array
    {
        $where = ['person_id' => $personId, 'status' => 'active'] + ($academicYearId === null ? [] : ['academic_year_id' => $academicYearId]);

        return array_map(self::info(...), $this->store->select(self::TABLE, $tenantId, $establishmentId, $where, 'element_code, group_id, session_kind'));
    }

    public function forElement(string $tenantId, string $establishmentId, string $elementId, ?string $groupId = null): array
    {
        $where = ['element_id' => $elementId, 'status' => 'active'] + ($groupId === null ? [] : ['group_id' => $groupId]);

        return array_map(self::info(...), $this->store->select(self::TABLE, $tenantId, $establishmentId, $where, 'group_id, session_kind'));
    }

    public function isAssigned(string $tenantId, string $establishmentId, string $personId, string $elementId, ?string $groupId = null): bool
    {
        $where = ['person_id' => $personId, 'element_id' => $elementId, 'status' => 'active'] + ($groupId === null ? [] : ['group_id' => $groupId]);

        return $this->store->count(self::TABLE, $tenantId, $establishmentId, $where) > 0;
    }

    /** @param array<string, mixed> $row */
    private static function info(array $row): TeachingAssignmentInfo
    {
        return new TeachingAssignmentInfo(
            (string) $row['id'],
            (string) $row['person_id'],
            (string) $row['school_id'],
            (string) $row['department_id'],
            (string) $row['program_id'],
            (string) $row['level_id'],
            (string) $row['group_id'],
            (string) $row['curriculum_id'],
            (string) $row['element_id'],
            (string) $row['element_code'],
            (string) $row['element_title'],
            (string) $row['session_kind'],
            (int) $row['planned_minutes'],
            (string) $row['academic_year_id'],
            (string) $row['status'],
            $row['staff_assignment_id'] === null ? null : (string) $row['staff_assignment_id'],
        );
    }
}
