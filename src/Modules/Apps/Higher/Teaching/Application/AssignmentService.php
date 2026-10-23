<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Teaching\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Higher\Teaching\Infrastructure\TeachingStore;
use Scholaris\Modules\Socle\Staff\Contracts\AssignmentDraft;
use Scholaris\Modules\Socle\Staff\Contracts\AssignmentWriter;
use Scholaris\Modules\Socle\Staff\Contracts\StaffDirectory;

/**
 * Affectations d'enseignement (EX-ENS-02) par le chef de département, limitées à SON département (périmètre `org_unit` de
 * `higher.assignment.manage`) : EC d'une maquette, groupe, nature CM/TD/TP, volume. L'affectation est aussi écrite au socle
 * (Staff AssignmentWriter, D17/D18 : EC et groupe en références externes) pour les heures et les vacations.
 * Accords écrits de dépassement du volume (RG-ENS-4) par le directeur d'école.
 */
final class AssignmentService
{
    public const TABLE = 'hi_assignments';
    public const OVERRUNS = 'hi_overrun_approvals';

    public function __construct(
        private readonly TeachingStore $store,
        private readonly TeachingAccess $access,
        private readonly ElementResolver $elements,
        private readonly StaffDirectory $staff,
        private readonly AssignmentWriter $writer,
        private readonly TeachingClock $clock,
    ) {
    }

    /** @return array<string, mixed> */
    public function assign(Principal $principal, string $establishmentId, string $personId, string $curriculumId, string $elementId, string $groupId, string $kind, int $minutes): array
    {
        $tenantId = $principal->tenantId;
        $place = $this->elements->locate($tenantId, $establishmentId, $curriculumId, $elementId, $groupId);
        $perimeter = $this->access->require($principal, $establishmentId, TeachingPermissions::ASSIGN);
        $this->access->assertWritable($principal, $perimeter, ['department_id' => $place['department_id']], 'hi_assignment', $elementId);
        $staff = $this->staff->find($tenantId, $personId);
        if ($staff === null || $staff->staffId === null || $staff->status !== 'active') {
            throw HttpException::validation(['person_id' => ['Enseignant introuvable ou inactif au module Personnel.']]);
        }
        foreach ($this->store->select(self::TABLE, $tenantId, $establishmentId, ['element_id' => $elementId, 'group_id' => $groupId, 'session_kind' => $kind, 'person_id' => $personId, 'status' => 'active']) as $existing) {
            throw HttpException::conflict('Cet enseignant est déjà affecté à cet EC, ce groupe et cette nature (' . $existing['id'] . ').');
        }
        $staffAssignmentId = $this->writer->assign($tenantId, $establishmentId, new AssignmentDraft(
            $staff->staffId,
            $kind,
            $minutes,
            elementRef: $elementId,
            groupRef: $groupId,
            level: $place['level_code'],
            academicYearId: $place['academic_year_id'],
            structureId: $groupId,
            subjectLabel: $place['element_title'],
        ), $principal->userId);
        $id = (string) new Ulid();
        $now = $this->clock->now();
        $row = [
            'id' => $id, 'school_id' => $place['school_id'], 'department_id' => $place['department_id'], 'program_id' => $place['program_id'],
            'level_id' => $place['level_id'], 'group_id' => $groupId, 'academic_year_id' => $place['academic_year_id'], 'curriculum_id' => $curriculumId,
            'element_id' => $elementId, 'element_code' => $place['element_code'], 'element_title' => $place['element_title'], 'person_id' => $personId,
            'staff_id' => $staff->staffId, 'session_kind' => $kind, 'planned_minutes' => $minutes, 'staff_assignment_id' => $staffAssignmentId,
            'status' => 'active', 'assigned_by' => $principal->userId, 'created_at' => $now, 'updated_at' => $now,
        ];
        $this->store->insert(self::TABLE, $tenantId, $establishmentId, $row);
        $this->access->audit($tenantId, $principal->userId, 'higher.assignment.created', 'hi_assignment', $id, null, $row);

        return $row + ['tenant_id' => $tenantId, 'establishment_id' => $establishmentId];
    }

    /** @return array<string, mixed> */
    public function change(Principal $principal, string $establishmentId, string $id, ?int $minutes, ?string $personId): array
    {
        $tenantId = $principal->tenantId;
        $row = $this->writable($principal, $establishmentId, $id);
        if ($row['status'] !== 'active') {
            throw HttpException::conflict('Affectation terminée.');
        }
        $changes = [];
        $socle = [];
        if ($minutes !== null) {
            $changes['planned_minutes'] = $minutes;
            $socle['planned_minutes'] = $minutes;
        }
        if ($personId !== null && $personId !== $row['person_id']) {
            throw HttpException::validation(['person_id' => ['Pour changer d\'enseignant, terminez l\'affectation et créez-en une autre (les heures restent rattachées).']]);
        }
        if ($changes === []) {
            return $row;
        }
        if ($row['staff_assignment_id'] !== null) {
            $this->writer->change($tenantId, $establishmentId, (string) $row['staff_assignment_id'], $socle);
        }
        $changes['updated_at'] = $this->clock->now();
        $this->store->update(self::TABLE, $tenantId, $establishmentId, $changes, ['id' => $id]);
        $this->access->audit($tenantId, $principal->userId, 'higher.assignment.changed', 'hi_assignment', $id, ['planned_minutes' => $row['planned_minutes']], $changes);

        return $this->store->findOrFail(self::TABLE, $tenantId, $establishmentId, $id);
    }

    /** @return array<string, mixed> */
    public function end(Principal $principal, string $establishmentId, string $id): array
    {
        $tenantId = $principal->tenantId;
        $row = $this->writable($principal, $establishmentId, $id);
        if ($row['status'] === 'active' && $row['staff_assignment_id'] !== null) {
            $this->writer->end($tenantId, $establishmentId, (string) $row['staff_assignment_id']);
        }
        $this->store->update(self::TABLE, $tenantId, $establishmentId, ['status' => 'ended', 'updated_at' => $this->clock->now()], ['id' => $id]);
        $this->access->audit($tenantId, $principal->userId, 'higher.assignment.ended', 'hi_assignment', $id, ['status' => $row['status']], ['status' => 'ended']);

        return $this->store->findOrFail(self::TABLE, $tenantId, $establishmentId, $id);
    }

    /**
     * RG-ENS-4 : accord écrit du directeur d'école pour des heures au-delà du volume affecté.
     *
     * @return array<string, mixed>
     */
    public function approveOverrun(Principal $principal, string $establishmentId, string $assignmentId, int $extraMinutes, string $documentRef, string $reason): array
    {
        $tenantId = $principal->tenantId;
        $row = $this->store->findOrFail(self::TABLE, $tenantId, $establishmentId, $assignmentId, 'Affectation introuvable.');
        $perimeter = $this->access->require($principal, $establishmentId, TeachingPermissions::OVERRUN_APPROVE);
        $this->access->assertWritable($principal, $perimeter, $row, 'hi_assignment', $assignmentId);
        $approval = [
            'id' => (string) new Ulid(), 'assignment_id' => $assignmentId, 'school_id' => $row['school_id'], 'department_id' => $row['department_id'],
            'program_id' => $row['program_id'], 'extra_minutes' => $extraMinutes, 'document_ref' => $documentRef, 'reason' => $reason,
            'approved_by' => $principal->userId, 'approved_at' => $this->clock->now(),
        ];
        $this->store->insert(self::OVERRUNS, $tenantId, $establishmentId, $approval);
        $this->access->audit($tenantId, $principal->userId, 'higher.assignment.overrun_approved', 'hi_assignment', $assignmentId, null, $approval);

        return $approval;
    }

    /** Minutes hors volume accordées par écrit pour une affectation. */
    public function approvedOverrun(string $tenantId, string $establishmentId, string $assignmentId): int
    {
        $total = 0;
        foreach ($this->store->select(self::OVERRUNS, $tenantId, $establishmentId, ['assignment_id' => $assignmentId]) as $approval) {
            $total += (int) $approval['extra_minutes'];
        }

        return $total;
    }

    /** @return array<string, mixed> */
    public function readable(Principal $principal, string $establishmentId, string $id): array
    {
        $row = $this->store->findOrFail(self::TABLE, $principal->tenantId, $establishmentId, $id, 'Affectation introuvable.');
        $me = $this->access->personOf($principal);
        if ($me !== null && $me === $row['person_id'] && $this->access->has($principal, $establishmentId, TeachingPermissions::LOG_WRITE)) {
            return $row;
        }
        $this->access->assertReadable($this->access->require($principal, $establishmentId, TeachingPermissions::ASSIGN_READ), $row);

        return $row;
    }

    /** @return array<string, mixed> */
    private function writable(Principal $principal, string $establishmentId, string $id): array
    {
        $row = $this->store->findOrFail(self::TABLE, $principal->tenantId, $establishmentId, $id, 'Affectation introuvable.');
        $perimeter = $this->access->require($principal, $establishmentId, TeachingPermissions::ASSIGN);
        $this->access->assertWritable($principal, $perimeter, ['department_id' => (string) $row['department_id']], 'hi_assignment', $id);

        return $row;
    }
}
