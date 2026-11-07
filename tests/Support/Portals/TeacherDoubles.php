<?php

declare(strict_types=1);

namespace Tests\Support\Portals;

use Scholaris\Modules\Socle\Portals\Contracts\GradingTodo;
use Scholaris\Modules\Socle\Staff\Contracts\StaffDirectory;
use Scholaris\Modules\Socle\Staff\Contracts\StaffInfo;
use Scholaris\Modules\Socle\Staff\Contracts\TeacherWorkload;

/** Doublure unique du personnel, des affectations, des heures et des saisies à faire pour le portail enseignant. */
final class TeacherDoubles implements StaffDirectory, TeacherWorkload, GradingTodo
{
    /** @var array<string, StaffInfo> */
    public array $staff = [];
    /** @var array<string, list<array{establishment_id: string, structure_id: ?string, subject_label: ?string, session_kind: string}>> */
    public array $assignments = [];
    /** @var array<string, array{declared_minutes: int, validated_minutes: int, rejected_minutes: int}> */
    public array $hours = [];
    /** @var array<string, list<array{assessment_id: string, label: string, establishment_id: string, structure_id: ?string, missing_grades: int}>>|null */
    public ?array $todo = null;

    public function find(string $tenantId, string $personId): ?StaffInfo
    {
        return $this->staff[$tenantId . '|' . $personId] ?? null;
    }

    public function teacherIdsIn(string $tenantId, string $establishmentId): array
    {
        return [];
    }

    public function assignmentsOf(string $tenantId, string $personId): array
    {
        return $this->assignments[$tenantId . '|' . $personId] ?? [];
    }

    public function hoursOf(string $tenantId, string $establishmentId, string $personId, string $month): array
    {
        return ['month' => $month] + ($this->hours[$tenantId . '|' . $establishmentId . '|' . $personId] ?? ['declared_minutes' => 0, 'validated_minutes' => 0, 'rejected_minutes' => 0]);
    }

    public function pendingFor(string $tenantId, string $teacherPersonId): ?array
    {
        return $this->todo[$tenantId . '|' . $teacherPersonId] ?? ($this->todo === null ? null : []);
    }
}
