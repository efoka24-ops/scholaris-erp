<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Teaching\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\AcademicDirectory;
use Scholaris\Modules\Apps\Higher\Curriculum\Contracts\CurriculumDirectory;
use Scholaris\Modules\Apps\Higher\Teaching\Contracts\TeachingImporter;
use Scholaris\Modules\Apps\Higher\Teaching\Infrastructure\TeachingStore;
use Scholaris\Modules\Socle\Staff\Contracts\AssignmentDraft;
use Scholaris\Modules\Socle\Staff\Contracts\AssignmentWriter;
use Scholaris\Modules\Socle\Staff\Contracts\StaffDirectory;

/**
 * Dossiers enseignants et affectations repris (TeachingImporter). Les mêmes tables et le même AssignmentWriter du socle que
 * l'affectation courante (AssignmentService), sans contrôle de périmètre : l'appelant (Migration) l'a déjà fait ligne par ligne.
 */
final class TeachingImport implements TeachingImporter
{
    private const KINDS = ['cm', 'td', 'tp', 'other'];

    public function __construct(
        private readonly TeachingStore $store,
        private readonly TeachingAccess $access,
        private readonly StaffDirectory $staff,
        private readonly AssignmentWriter $writer,
        private readonly AcademicDirectory $academic,
        private readonly CurriculumDirectory $curricula,
        private readonly TeachingClock $clock,
    ) {
    }

    public function checkTeacher(string $tenantId, string $personId, string $teacherStatus, bool $publicServant, ?string $authorizationRef, ?string $authorizationDocument): ?string
    {
        $staff = $this->staff->find($tenantId, $personId);
        if ($staff === null || $staff->staffId === null) {
            return 'Fiche du personnel introuvable : créez d\'abord l\'enseignant au module Personnel.';
        }
        if ($staff->status !== 'active') {
            return 'La fiche du personnel n\'est pas active.';
        }
        if (!in_array($teacherStatus, TeacherService::STATUSES, true)) {
            return 'Statut inconnu : permanent, vacataire ou invité.';
        }
        if ($teacherStatus === 'vacataire' && $publicServant && ($authorizationRef === null || $authorizationDocument === null)) {
            return 'Un vacataire fonctionnaire doit produire l\'autorisation de son employeur (référence et document).';
        }

        return null;
    }

    public function checkAssignment(string $tenantId, string $establishmentId, string $personId, string $programId, string $levelId, string $groupId, string $academicYearId, string $elementCode): ?string
    {
        $staff = $this->staff->find($tenantId, $personId);
        if ($staff === null || $staff->staffId === null || $staff->status !== 'active') {
            return 'Enseignant introuvable ou inactif au module Personnel.';
        }
        $group = $this->academic->group($tenantId, $establishmentId, $groupId);
        if ($group === null || $group->levelId !== $levelId) {
            return 'Le groupe n\'appartient pas au niveau indiqué.';
        }
        $curriculum = $this->curricula->publishedFor($tenantId, $establishmentId, $programId, $levelId, $academicYearId);
        if ($curriculum === null) {
            return 'Aucune maquette publiée pour cette filière, ce niveau et cette année.';
        }

        return $this->element($tenantId, $establishmentId, $curriculum->id, $elementCode) === null ? 'EC « ' . $elementCode . ' » absent de la maquette publiée.' : null;
    }

    public function importTeacher(string $tenantId, string $establishmentId, string $actorId, string $personId, ?string $departmentId, string $teacherStatus, ?string $grade, bool $publicServant, ?string $authorizationRef, ?string $authorizationDocument, ?string $authorizationUntil): array
    {
        $refusal = $this->checkTeacher($tenantId, $personId, $teacherStatus, $publicServant, $authorizationRef, $authorizationDocument);
        if ($refusal !== null) {
            throw HttpException::validation(['person_id' => [$refusal]]);
        }
        $staff = $this->staff->find($tenantId, $personId);
        $current = $this->store->select(TeacherService::TABLE, $tenantId, $establishmentId, ['person_id' => $personId], 'id', 1)[0] ?? null;
        if ($current !== null) {
            return ['profile_id' => (string) $current['id'], 'created' => false];
        }
        $schoolId = null;
        if ($departmentId !== null) {
            foreach ($this->store->select('hi_departments', $tenantId, $establishmentId, ['id' => $departmentId], 'id', 1) as $department) {
                $schoolId = (string) $department['school_id'];
            }
        }
        $id = (string) new Ulid();
        $now = $this->clock->now();
        $row = [
            'id' => $id, 'person_id' => $personId, 'staff_id' => $staff?->staffId, 'school_id' => $schoolId, 'department_id' => $departmentId,
            'teacher_status' => $teacherStatus, 'grade' => $grade ?? $staff?->grade, 'diplomas' => '[]', 'origin_establishment' => null,
            'public_servant' => $publicServant ? 1 : 0, 'employer_authorization_ref' => $authorizationRef,
            'employer_authorization_document' => $authorizationDocument, 'employer_authorization_until' => $authorizationUntil,
            'created_by' => $actorId, 'created_at' => $now, 'updated_at' => $now,
        ];
        $this->store->insert(TeacherService::TABLE, $tenantId, $establishmentId, $row);
        $this->access->audit($tenantId, $actorId, 'higher.teacher.imported', 'hi_teacher_profile', $id, null, $row);

        return ['profile_id' => $id, 'created' => true];
    }

    public function importAssignment(string $tenantId, string $establishmentId, string $actorId, string $personId, string $programId, string $levelId, string $groupId, string $academicYearId, string $elementCode, string $kind, int $minutes): array
    {
        $refusal = $this->checkAssignment($tenantId, $establishmentId, $personId, $programId, $levelId, $groupId, $academicYearId, $elementCode);
        if ($refusal !== null) {
            throw HttpException::validation(['assignment' => [$refusal]]);
        }
        if (!in_array($kind, self::KINDS, true) || $minutes <= 0) {
            throw HttpException::validation(['kind' => ['Nature (cm, td, tp) et volume en minutes positif exigés.']]);
        }
        $curriculum = $this->curricula->publishedFor($tenantId, $establishmentId, $programId, $levelId, $academicYearId) ?? throw HttpException::notFound('Maquette introuvable.');
        $element = $this->element($tenantId, $establishmentId, $curriculum->id, $elementCode) ?? throw HttpException::notFound('Élément constitutif introuvable.');
        foreach ($this->store->select(AssignmentService::TABLE, $tenantId, $establishmentId, ['element_id' => $element['id'], 'group_id' => $groupId, 'session_kind' => $kind, 'person_id' => $personId, 'status' => 'active'], 'id', 1) as $existing) {
            return ['assignment_id' => (string) $existing['id'], 'created' => false];
        }
        $program = $this->academic->program($tenantId, $establishmentId, $programId) ?? throw HttpException::notFound('Filière introuvable.');
        $level = $this->academic->level($tenantId, $establishmentId, $levelId) ?? throw HttpException::notFound('Niveau introuvable.');
        $staff = $this->staff->find($tenantId, $personId) ?? throw HttpException::notFound('Fiche du personnel introuvable.');

        return $this->store->transaction(function () use ($tenantId, $establishmentId, $actorId, $personId, $program, $level, $groupId, $academicYearId, $curriculum, $element, $kind, $minutes, $staff): array {
            $staffAssignmentId = $this->writer->assign($tenantId, $establishmentId, new AssignmentDraft(
                (string) $staff->staffId,
                $kind,
                $minutes,
                elementRef: (string) $element['id'],
                groupRef: $groupId,
                level: $level->code,
                academicYearId: $academicYearId,
                structureId: $groupId,
                subjectLabel: (string) $element['title'],
            ), $actorId);
            $id = (string) new Ulid();
            $now = $this->clock->now();
            $row = [
                'id' => $id, 'school_id' => $program->schoolId, 'department_id' => $program->departmentId, 'program_id' => $program->id, 'level_id' => $level->id,
                'group_id' => $groupId, 'academic_year_id' => $academicYearId, 'curriculum_id' => $curriculum->id, 'element_id' => $element['id'],
                'element_code' => $element['code'], 'element_title' => $element['title'], 'person_id' => $personId, 'staff_id' => $staff->staffId,
                'session_kind' => $kind, 'planned_minutes' => $minutes, 'staff_assignment_id' => $staffAssignmentId, 'status' => 'active',
                'assigned_by' => $actorId, 'created_at' => $now, 'updated_at' => $now,
            ];
            $this->store->insert(AssignmentService::TABLE, $tenantId, $establishmentId, $row);
            $this->access->audit($tenantId, $actorId, 'higher.assignment.imported', 'hi_assignment', $id, null, $row);

            return ['assignment_id' => $id, 'created' => true];
        });
    }

    public function revertAssignment(string $tenantId, string $establishmentId, string $actorId, string $assignmentId): void
    {
        $row = $this->store->find(AssignmentService::TABLE, $tenantId, $establishmentId, $assignmentId);
        if ($row === null) {
            return;
        }
        foreach (['hi_timetable_entries' => 'créneau d\'emploi du temps', 'hi_session_logs' => 'séance du cahier de textes', 'hi_overrun_approvals' => 'accord de dépassement'] as $table => $label) {
            if ($this->store->count($table, $tenantId, $establishmentId, ['assignment_id' => $assignmentId]) > 0) {
                throw HttpException::conflict('Annulation impossible : un ' . $label . ' dépend de cette affectation.');
            }
        }
        $this->store->transaction(function () use ($tenantId, $establishmentId, $actorId, $assignmentId, $row): void {
            if ($row['status'] === 'active' && $row['staff_assignment_id'] !== null) {
                $this->writer->end($tenantId, $establishmentId, (string) $row['staff_assignment_id']);
            }
            $this->store->delete(AssignmentService::TABLE, $tenantId, $establishmentId, ['id' => $assignmentId]);
            $this->access->audit($tenantId, $actorId, 'higher.assignment.import_reverted', 'hi_assignment', $assignmentId, ['status' => $row['status']], null);
        });
    }

    public function revertTeacher(string $tenantId, string $establishmentId, string $profileId): void
    {
        $profile = $this->store->find(TeacherService::TABLE, $tenantId, $establishmentId, $profileId);
        if ($profile === null) {
            return;
        }
        if ($this->store->count(AssignmentService::TABLE, $tenantId, $establishmentId, ['person_id' => $profile['person_id']]) === 0) {
            $this->store->delete(TeacherService::TABLE, $tenantId, $establishmentId, ['id' => $profileId]);
        }
    }

    /** @return array{id: string, code: string, title: string}|null */
    private function element(string $tenantId, string $establishmentId, string $curriculumId, string $code): ?array
    {
        $wanted = strtoupper(trim($code));
        foreach ($this->curricula->units($tenantId, $establishmentId, $curriculumId) as $unit) {
            foreach ($unit->elements as $element) {
                if (strtoupper($element['code']) === $wanted) {
                    return ['id' => $element['id'], 'code' => $element['code'], 'title' => $element['title']];
                }
            }
        }

        return null;
    }
}
