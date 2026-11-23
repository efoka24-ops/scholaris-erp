<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Enrolment\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\AcademicDirectory;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\EnrolmentEligibility;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\LevelInfo;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\ProgramInfo;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\RegulationBook;
use Scholaris\Modules\Apps\Higher\Curriculum\Contracts\CurriculumDirectory;
use Scholaris\Modules\Apps\Higher\Enrolment\Contracts\EnrolmentImporter;
use Scholaris\Modules\Apps\Higher\Enrolment\Domain\EnrolmentStatus;
use Scholaris\Modules\Apps\Higher\Enrolment\Infrastructure\EnrolmentStore;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentWriter;
use Scholaris\Modules\Socle\People\Contracts\LearnerWriter;

/**
 * Inscriptions reprises (EnrolmentImporter) : mêmes tables et mêmes gardes que l'inscription courante, sans le contrôle des frais
 * d'inscription (le solde d'ouverture est repris par Billing). Les règles RG-INS-1 (matricule), RG-ACA-1/2 (autorisation) et RG-MAQ-1
 * (version de maquette) s'appliquent.
 */
final class EnrolmentImport implements EnrolmentImporter
{
    public function __construct(
        private readonly EnrolmentStore $store,
        private readonly EnrolmentAccess $access,
        private readonly AcademicDirectory $academic,
        private readonly EnrolmentEligibility $eligibility,
        private readonly RegulationBook $regulations,
        private readonly CurriculumDirectory $curricula,
        private readonly MatriculeService $matricules,
        private readonly LearningContractService $contracts,
        private readonly EnrolmentClock $clock,
        private readonly EnrollmentWriter $writer,
        private readonly LearnerWriter $learners,
    ) {
    }

    public function importEnrolled(string $tenantId, string $establishmentId, string $actorId, string $personId, string $levelId, ?string $groupId, string $academicYearId, ?string $studyMode, ?string $matricule, int $attemptNumber): array
    {
        [$program, $level] = $this->structure($tenantId, $establishmentId, $levelId);
        if ($groupId !== null) {
            $group = $this->academic->group($tenantId, $establishmentId, $groupId);
            if ($group === null || $group->levelId !== $level->id) {
                throw HttpException::validation(['group_id' => ['Groupe introuvable dans ce niveau.']]);
            }
        }

        return $this->store->transaction(function () use ($tenantId, $establishmentId, $actorId, $personId, $level, $program, $groupId, $academicYearId, $studyMode, $matricule, $attemptNumber): array {
            $existing = $this->live($tenantId, $establishmentId, $personId, $academicYearId, $program->id);
            if ($existing !== null) {
                $known = $this->store->find(MatriculeService::STUDENTS, $tenantId, $establishmentId, (string) $existing['student_id']);

                return [
                    'enrolment_id' => (string) $existing['id'], 'student_id' => (string) $existing['student_id'],
                    'matricule' => isset($known['matricule']) ? (string) $known['matricule'] : null,
                    'created' => false, 'roll_call' => $existing['enrollment_id'] === null ? 'no_group' : 'linked',
                ];
            }
            $refusal = $this->refusal($tenantId, $establishmentId, $level->id, $groupId, $academicYearId);
            if ($refusal !== null) {
                throw HttpException::validation(['level_id' => [$refusal]], 'Inscription refusée : ' . $refusal);
            }
            $student = $this->matricules->ensureStudent($tenantId, $establishmentId, $personId, $program);
            if ($student['matricule'] === null) {
                if ($matricule !== null && $matricule !== '') {
                    if ($this->store->count(MatriculeService::STUDENTS, $tenantId, $establishmentId, ['matricule' => $matricule]) > 0) {
                        throw HttpException::conflict('Ce matricule est déjà attribué à un autre étudiant.');
                    }
                    $this->store->update(MatriculeService::STUDENTS, $tenantId, $establishmentId, [
                        'matricule' => $matricule, 'matricule_assigned_at' => $this->clock->now(), 'updated_at' => $this->clock->now(),
                    ], ['id' => $student['id']]);
                } else {
                    $this->matricules->assign($tenantId, $establishmentId, $personId, $program, (int) $this->clock->at()->format('Y'));
                }
            }
            $student = $this->store->findOrFail(MatriculeService::STUDENTS, $tenantId, $establishmentId, (string) $student['id']);
            $this->regulations->freezeForEnrolment($tenantId, $establishmentId, $program->schoolId, $program->regime, $academicYearId, $actorId);
            $curriculum = $this->curricula->publishedFor($tenantId, $establishmentId, $program->id, $level->id, $academicYearId);
            $id = (string) new Ulid();
            $now = $this->clock->now();
            $this->store->insert(EnrolmentService::TABLE, $tenantId, $establishmentId, [
                'id' => $id, 'student_id' => $student['id'], 'person_id' => $personId, 'enrollment_id' => null, 'application_id' => null,
                'academic_year_id' => $academicYearId, 'school_id' => $program->schoolId, 'department_id' => $program->departmentId, 'program_id' => $program->id,
                'level_id' => $level->id, 'group_id' => $groupId, 'study_mode' => $studyMode ?? $program->studyMode,
                'enrolment_kind' => $attemptNumber > 1 ? 'reenrolment' : 'new', 'curriculum_id' => $curriculum?->id, 'curriculum_version' => $curriculum?->version,
                'attempt_number' => max(1, $attemptNumber), 'derogation_id' => null, 'status' => EnrolmentStatus::ENROLLED, 'status_reason' => 'Reprise de données',
                'financial_hold' => 0, 'validated_by' => $actorId, 'validated_at' => $now, 'created_by' => $actorId, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $enrolment = $this->store->findOrFail(EnrolmentService::TABLE, $tenantId, $establishmentId, $id);
            $this->contracts->build($enrolment, [], []);
            $this->event($tenantId, $establishmentId, $id, $personId, 'imported', null, EnrolmentStatus::ENROLLED, 'Reprise de données', $actorId);
            $this->access->audit($tenantId, $actorId, 'higher.enrolment.imported', 'hi_enrolment', $id, null, [
                'person_id' => $personId, 'level_id' => $level->id, 'academic_year_id' => $academicYearId, 'attempt_number' => $attemptNumber,
                'matricule' => $student['matricule'],
            ]);

            return [
                'enrolment_id' => $id, 'student_id' => (string) $student['id'], 'matricule' => $student['matricule'] === null ? null : (string) $student['matricule'],
                'created' => true, 'roll_call' => $this->rollCall($tenantId, $establishmentId, $actorId, $id, $personId, $groupId, $academicYearId),
            ];
        });
    }

    public function refusal(string $tenantId, string $establishmentId, string $levelId, ?string $groupId, string $academicYearId): ?string
    {
        return $this->eligibility->refusalFor($tenantId, $establishmentId, $groupId ?? $levelId, $academicYearId, $this->clock->at());
    }

    public function addCarryOver(string $tenantId, string $establishmentId, string $actorId, string $personId, string $academicYearId, string $programId, string $origin, string $unitCode, string $unitTitle, int $credits, string $unitLevelId): ?string
    {
        if (!in_array($origin, [LearningContractService::DEBT, LearningContractService::CAPITALIZED], true)) {
            throw HttpException::validation(['origin' => ['Origine inconnue : debt ou capitalized.']]);
        }
        $enrolment = $this->live($tenantId, $establishmentId, $personId, $academicYearId, $programId);
        if ($enrolment === null) {
            return null;
        }
        $contract = $this->store->select(LearningContractService::CONTRACTS, $tenantId, $establishmentId, ['enrolment_id' => $enrolment['id']], 'id', 1)[0] ?? null;
        if ($contract === null) {
            return null;
        }
        $same = $this->store->select(LearningContractService::ITEMS, $tenantId, $establishmentId, ['contract_id' => $contract['id'], 'unit_code' => $unitCode], 'id', 10);
        foreach ($same as $item) {
            if ($item['item_origin'] === $origin) {
                return (string) $item['id'];
            }
        }
        if ($origin === LearningContractService::CAPITALIZED && $unitLevelId === $enrolment['level_id']) {
            foreach ($same as $item) {
                if ($item['item_origin'] === LearningContractService::NORMAL) {
                    $this->store->update(LearningContractService::ITEMS, $tenantId, $establishmentId, ['item_origin' => $origin], ['id' => $item['id']]);
                    $this->access->audit($tenantId, $actorId, 'higher.learning_contract.imported', 'hi_learning_contract_item', (string) $item['id'], ['item_origin' => 'normal'], ['item_origin' => $origin]);

                    return (string) $item['id'];
                }
            }
        }
        $id = (string) new Ulid();
        $this->store->insert(LearningContractService::ITEMS, $tenantId, $establishmentId, [
            'id' => $id, 'contract_id' => $contract['id'], 'enrolment_id' => $enrolment['id'], 'curriculum_unit_id' => null,
            'unit_code' => $unitCode, 'unit_title' => mb_substr($unitTitle, 0, 160), 'credits' => $credits, 'semester_ordinal' => null,
            'item_origin' => $origin, 'choice_group' => null, 'source_level_id' => $unitLevelId, 'created_at' => $this->clock->now(),
        ]);
        $this->access->audit($tenantId, $actorId, 'higher.learning_contract.imported', 'hi_learning_contract_item', $id, null, ['unit_code' => $unitCode, 'item_origin' => $origin, 'credits' => $credits]);

        return $id;
    }

    public function removeCarryOver(string $tenantId, string $establishmentId, string $itemId): void
    {
        $this->store->delete(LearningContractService::ITEMS, $tenantId, $establishmentId, ['id' => $itemId]);
    }

    public function revertImported(string $tenantId, string $establishmentId, string $actorId, string $enrolmentId, string $reason): void
    {
        $enrolment = $this->store->find(EnrolmentService::TABLE, $tenantId, $establishmentId, $enrolmentId);
        if ($enrolment === null) {
            return;
        }
        $personId = (string) $enrolment['person_id'];
        if ($this->store->count(EnrolmentService::DECISIONS, $tenantId, $establishmentId, ['person_id' => $personId, 'program_id' => $enrolment['program_id']]) > 0) {
            throw HttpException::conflict('Annulation impossible : une décision de passage dépend de cette inscription.');
        }
        if ($this->store->count(EnrolmentService::TABLE, $tenantId, $establishmentId, ['person_id' => $personId]) > 1) {
            throw HttpException::conflict('Annulation impossible : l\'étudiant a d\'autres inscriptions.');
        }
        $this->store->transaction(function () use ($tenantId, $establishmentId, $actorId, $enrolment, $reason, $enrolmentId, $personId): void {
            $contract = $this->store->select(LearningContractService::CONTRACTS, $tenantId, $establishmentId, ['enrolment_id' => $enrolmentId], 'id', 1)[0] ?? null;
            if ($contract !== null) {
                $this->store->delete(LearningContractService::ITEMS, $tenantId, $establishmentId, ['contract_id' => $contract['id']]);
                $this->store->delete(LearningContractService::CONTRACTS, $tenantId, $establishmentId, ['id' => $contract['id']]);
            }
            if ($enrolment['enrollment_id'] !== null) {
                try {
                    $this->learners->closeEnrollment($tenantId, (string) $enrolment['enrollment_id'], 'withdrawn', $this->clock->today());
                } catch (HttpException) {
                    // Inscription du socle déjà close ou introuvable : rien à défaire.
                }
            }
            $this->store->delete(EnrolmentService::TABLE, $tenantId, $establishmentId, ['id' => $enrolmentId]);
            $this->event($tenantId, $establishmentId, null, $personId, 'import_reverted', EnrolmentStatus::ENROLLED, null, $reason, $actorId);
            $this->access->audit($tenantId, $actorId, 'higher.enrolment.import_reverted', 'hi_enrolment', $enrolmentId, ['status' => $enrolment['status']], ['reason' => $reason]);
            $this->store->delete(MatriculeService::STUDENTS, $tenantId, $establishmentId, ['id' => $enrolment['student_id']]);
        });
    }

    /** @return array<string, mixed>|null */
    private function live(string $tenantId, string $establishmentId, string $personId, string $year, string $programId): ?array
    {
        foreach ($this->store->select(EnrolmentService::TABLE, $tenantId, $establishmentId, ['person_id' => $personId, 'academic_year_id' => $year, 'program_id' => $programId], 'id', 10) as $row) {
            if (in_array($row['status'], EnrolmentStatus::LIVE, true)) {
                return $row;
            }
        }

        return null;
    }

    /** @return array{0: ProgramInfo, 1: LevelInfo} */
    private function structure(string $tenantId, string $establishmentId, string $levelId): array
    {
        $level = $this->academic->level($tenantId, $establishmentId, $levelId) ?? throw HttpException::validation(['level_id' => ['Niveau introuvable dans cet établissement.']]);
        $program = $this->academic->program($tenantId, $establishmentId, $level->programId) ?? throw HttpException::validation(['level_id' => ['Filière introuvable.']]);

        return [$program, $level];
    }

    private function rollCall(string $tenantId, string $establishmentId, string $actorId, string $enrolmentId, string $personId, ?string $groupId, string $year): string
    {
        if ($groupId === null) {
            return 'no_group';
        }
        try {
            $enrollmentId = $this->writer->enrol($tenantId, $establishmentId, $personId, $groupId, $year, $actorId);
        } catch (HttpException $e) {
            $reasons = array_merge(...array_values(array_map(static fn (mixed $m): array => array_map('strval', (array) $m), $e->errors)));

            return 'pending:' . ($reasons === [] ? $e->getMessage() : implode(' ', $reasons));
        }
        $this->store->update(EnrolmentService::TABLE, $tenantId, $establishmentId, ['enrollment_id' => $enrollmentId, 'updated_at' => $this->clock->now()], ['id' => $enrolmentId]);

        return 'linked';
    }

    private function event(string $tenantId, string $establishmentId, ?string $enrolmentId, string $personId, string $type, ?string $from, ?string $to, ?string $reason, string $actorId): void
    {
        $this->store->insert(EnrolmentService::EVENTS, $tenantId, $establishmentId, [
            'id' => (string) new Ulid(), 'enrolment_id' => $enrolmentId, 'person_id' => $personId, 'event_type' => $type,
            'from_value' => $from, 'to_value' => $to, 'reason' => $reason === null ? null : mb_substr($reason, 0, 500), 'actor_id' => $actorId, 'occurred_at' => $this->clock->now(),
        ]);
    }
}
