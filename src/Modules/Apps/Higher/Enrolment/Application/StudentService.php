<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Enrolment\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\AcademicDirectory;
use Scholaris\Modules\Apps\Higher\Enrolment\Contracts\ProgressionDecisionRecorder;
use Scholaris\Modules\Apps\Higher\Enrolment\Domain\ProgressionRules;
use Scholaris\Modules\Apps\Higher\Enrolment\Infrastructure\EnrolmentStore;
use Scholaris\Modules\Socle\Identity\Contracts\UserPersonLink;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/**
 * Dossier étudiant (EX-INS-01 : matricule, photo, garant ; tous ses parcours en double cursus, RG-ORG-6), consentement de partage
 * des notes et absences avec le garant (RG-INS-7, décidé par l'étudiant ou recueilli par la scolarité), dérogations du directeur
 * d'école (RG-INS-5) et décisions de passage de l'année N (RG-INS-3, saisies ou reçues de la délibération).
 */
final class StudentService implements ProgressionDecisionRecorder
{
    public function __construct(
        private readonly EnrolmentStore $store,
        private readonly EnrolmentAccess $access,
        private readonly AcademicDirectory $academic,
        private readonly UserPersonLink $links,
        private readonly PersonDirectory $people,
        private readonly EnrolmentClock $clock,
    ) {
    }

    /**
     * Dossier lisible par la personne elle-même ou par qui couvre l'une de ses inscriptions.
     *
     * @return array<string, mixed>
     */
    public function show(Principal $principal, string $establishmentId, string $personId): array
    {
        $student = $this->student($principal->tenantId, $establishmentId, $personId);
        if (!$this->isSelf($principal, $personId)) {
            $this->assertCovered($principal, $establishmentId, $personId, EnrolmentPermissions::READ, false);
        }
        $enrolments = $this->store->select(EnrolmentService::TABLE, $principal->tenantId, $establishmentId, ['person_id' => $personId], 'academic_year_id DESC, created_at DESC', 200);
        $person = $this->people->find($principal->tenantId, $personId);

        return [
            'person_id' => $personId, 'full_name' => $person?->fullName(), 'matricule' => $student['matricule'],
            'matricule_assigned_at' => $student['matricule_assigned_at'], 'photo_ref' => $student['photo_ref'],
            'guarantor_person_id' => $student['guarantor_person_id'], 'share_with_guarantor' => (int) $student['share_with_guarantor'] === 1,
            'consent_recorded_at' => $student['consent_recorded_at'],
            'tracks' => array_map(static fn (array $e): array => [
                'enrolment_id' => $e['id'], 'academic_year_id' => $e['academic_year_id'], 'school_id' => $e['school_id'], 'program_id' => $e['program_id'],
                'level_id' => $e['level_id'], 'group_id' => $e['group_id'], 'status' => $e['status'], 'attempt_number' => (int) $e['attempt_number'],
            ], $enrolments),
        ];
    }

    /**
     * Photo et garant du dossier (scolarité).
     *
     * @return array<string, mixed>
     */
    public function update(Principal $principal, string $establishmentId, string $personId, ?string $photoRef, ?string $guarantorId): array
    {
        $student = $this->student($principal->tenantId, $establishmentId, $personId);
        $this->assertCovered($principal, $establishmentId, $personId, [EnrolmentPermissions::ENROL], true);
        $changes = array_filter(['photo_ref' => $photoRef, 'guarantor_person_id' => $guarantorId], static fn (?string $v): bool => $v !== null);
        if ($guarantorId !== null && $this->people->find($principal->tenantId, $guarantorId) === null) {
            throw HttpException::validation(['guarantor_person_id' => ['Garant introuvable dans ce client.']]);
        }
        $this->store->update(MatriculeService::STUDENTS, $principal->tenantId, $establishmentId, $changes + ['updated_at' => $this->clock->now()], ['id' => $student['id']]);
        $this->access->audit($principal->tenantId, $principal->userId, 'higher.student.updated', 'hi_student', (string) $student['id'], null, $changes);

        return $this->show($principal, $establishmentId, $personId);
    }

    /**
     * RG-INS-7 : l'étudiant décide du partage de ses notes et absences avec son garant (ou la scolarité enregistre son choix écrit).
     * Révocable ; tracé (journal d'audit et événements).
     *
     * @return array<string, mixed>
     */
    public function consent(Principal $principal, string $establishmentId, string $personId, bool $share): array
    {
        $student = $this->student($principal->tenantId, $establishmentId, $personId);
        if (!$this->isSelf($principal, $personId)) {
            $this->assertCovered($principal, $establishmentId, $personId, [EnrolmentPermissions::ENROL], true);
        }
        $this->store->update(MatriculeService::STUDENTS, $principal->tenantId, $establishmentId, [
            'share_with_guarantor' => $share ? 1 : 0, 'consent_recorded_by' => $principal->userId, 'consent_recorded_at' => $this->clock->now(),
            'updated_at' => $this->clock->now(),
        ], ['id' => $student['id']]);
        $this->store->insert(EnrolmentService::EVENTS, $principal->tenantId, $establishmentId, [
            'id' => (string) new Ulid(), 'enrolment_id' => null, 'person_id' => $personId, 'event_type' => 'guarantor_consent',
            'from_value' => (int) $student['share_with_guarantor'] === 1 ? 'shared' : 'private', 'to_value' => $share ? 'shared' : 'private',
            'reason' => $this->isSelf($principal, $personId) ? 'choix de l\'étudiant' : 'choix recueilli par la scolarité', 'actor_id' => $principal->userId,
            'occurred_at' => $this->clock->now(),
        ]);
        $this->access->audit($principal->tenantId, $principal->userId, 'higher.student.guarantor_consent', 'hi_student', (string) $student['id'], ['share' => (int) $student['share_with_guarantor'] === 1], ['share' => $share]);

        return $this->show($principal, $establishmentId, $personId);
    }

    /**
     * Dérogation du directeur d'école à la limite d'inscriptions dans un niveau (RG-INS-5), motivée, pour une année.
     *
     * @return array<string, mixed>
     */
    public function derogate(Principal $principal, string $establishmentId, string $personId, string $levelId, string $academicYearId, string $reason): array
    {
        $tenantId = $principal->tenantId;
        $level = $this->academic->level($tenantId, $establishmentId, $levelId) ?? throw HttpException::validation(['level_id' => ['Niveau introuvable.']]);
        $program = $this->academic->program($tenantId, $establishmentId, $level->programId) ?? throw HttpException::validation(['level_id' => ['Filière introuvable.']]);
        $perimeter = $this->access->require($principal, $establishmentId, [EnrolmentPermissions::DEROGATE]);
        $id = (string) new Ulid();
        $this->access->assertWritable($principal, $perimeter, [
            'school_id' => $program->schoolId, 'department_id' => $program->departmentId, 'program_id' => $program->id, 'level_id' => $level->id,
        ], 'hi_enrolment_derogation', $id);
        if ($this->people->find($tenantId, $personId) === null) {
            throw HttpException::validation(['person_id' => ['Personne introuvable dans ce client.']]);
        }
        $row = [
            'id' => $id, 'person_id' => $personId, 'school_id' => $program->schoolId, 'program_id' => $program->id, 'level_id' => $level->id,
            'academic_year_id' => $academicYearId, 'derogation_kind' => EnrolmentService::CONSECUTIVE, 'reason' => $reason,
            'granted_by' => $principal->userId, 'granted_at' => $this->clock->now(),
        ];
        $this->store->insert(EnrolmentService::DEROGATIONS, $tenantId, $establishmentId, $row);
        $this->access->audit($tenantId, $principal->userId, 'higher.enrolment.derogation', 'hi_enrolment_derogation', $id, null, $row);

        return $row;
    }

    /**
     * Décision de passage saisie par le directeur d'école (reprise, ou avant la délibération en ligne de la vague 3).
     *
     * @param list<string> $acquired
     * @param list<string> $missing
     * @return array<string, mixed>
     */
    public function recordDecision(Principal $principal, string $establishmentId, string $personId, string $levelId, string $academicYearId, string $decision, array $acquired, array $missing): array
    {
        $tenantId = $principal->tenantId;
        $level = $this->academic->level($tenantId, $establishmentId, $levelId) ?? throw HttpException::validation(['level_id' => ['Niveau introuvable.']]);
        $program = $this->academic->program($tenantId, $establishmentId, $level->programId) ?? throw HttpException::validation(['level_id' => ['Filière introuvable.']]);
        $perimeter = $this->access->require($principal, $establishmentId, [EnrolmentPermissions::VALIDATE]);
        $this->access->assertWritable($principal, $perimeter, [
            'school_id' => $program->schoolId, 'department_id' => $program->departmentId, 'program_id' => $program->id, 'level_id' => $level->id,
        ], 'hi_progression_decision', $personId);
        $this->record($tenantId, $establishmentId, $personId, $program->id, $level->id, $academicYearId, $decision, $acquired, $missing, $principal->userId);

        return $this->store->select(EnrolmentService::DECISIONS, $tenantId, $establishmentId, ['person_id' => $personId, 'program_id' => $program->id, 'academic_year_id' => $academicYearId], 'id', 1)[0] ?? [];
    }

    public function record(string $tenantId, string $establishmentId, string $personId, string $programId, string $levelId, string $academicYearId, string $decision, array $acquiredUnitCodes, array $missingUnitCodes, ?string $actorId): void
    {
        if (!in_array($decision, ProgressionRules::DECISIONS, true)) {
            throw HttpException::validation(['decision' => ['Décision attendue parmi : ' . implode(', ', ProgressionRules::DECISIONS) . '.']]);
        }
        $level = $this->academic->level($tenantId, $establishmentId, $levelId);
        $program = $this->academic->program($tenantId, $establishmentId, $programId);
        if ($level === null || $program === null || $level->programId !== $programId) {
            throw HttpException::validation(['level_id' => ['Niveau introuvable dans cette filière.']]);
        }
        $where = ['person_id' => $personId, 'program_id' => $programId, 'academic_year_id' => $academicYearId];
        $this->store->transaction(function () use ($tenantId, $establishmentId, $where, $program, $levelId, $decision, $acquiredUnitCodes, $missingUnitCodes, $actorId): void {
            $this->store->delete(EnrolmentService::DECISIONS, $tenantId, $establishmentId, $where);
            $this->store->insert(EnrolmentService::DECISIONS, $tenantId, $establishmentId, $where + [
                'id' => (string) new Ulid(), 'school_id' => $program->schoolId, 'level_id' => $levelId, 'decision' => $decision,
                'acquired_units_json' => json_encode(array_values(array_unique($acquiredUnitCodes)), JSON_THROW_ON_ERROR),
                'missing_units_json' => json_encode(array_values(array_unique($missingUnitCodes)), JSON_THROW_ON_ERROR),
                'recorded_by' => $actorId, 'recorded_at' => $this->clock->now(),
            ]);
        });
        $this->access->audit($tenantId, $actorId, 'higher.progression.recorded', 'hi_progression_decision', $personId, null, $where + ['decision' => $decision]);
    }

    /** @return array<string, mixed> */
    private function student(string $tenantId, string $establishmentId, string $personId): array
    {
        return $this->store->select(MatriculeService::STUDENTS, $tenantId, $establishmentId, ['person_id' => $personId], 'id', 1)[0]
            ?? throw HttpException::notFound('Dossier étudiant introuvable.');
    }

    private function isSelf(Principal $principal, string $personId): bool
    {
        return $this->links->personIdForUser($principal->tenantId, $principal->userId) === $personId;
    }

    /**
     * L'une des inscriptions de la personne doit être dans le périmètre (lecture : 404, écriture : 403 journalisé).
     *
     * @param list<string> $permissions
     */
    private function assertCovered(Principal $principal, string $establishmentId, string $personId, array $permissions, bool $write): void
    {
        $perimeter = $this->access->require($principal, $establishmentId, $permissions);
        $enrolments = $this->store->select(EnrolmentService::TABLE, $principal->tenantId, $establishmentId, ['person_id' => $personId], 'id', 200);
        foreach ($enrolments as $enrolment) {
            try {
                $this->access->assertReadable($perimeter, $enrolment);

                return;
            } catch (HttpException) {
            }
        }
        if ($write && $enrolments !== []) {
            $this->access->assertWritable($principal, $perimeter, $enrolments[0], 'hi_student', $personId);
        }

        throw HttpException::notFound('Dossier étudiant introuvable.');
    }
}
