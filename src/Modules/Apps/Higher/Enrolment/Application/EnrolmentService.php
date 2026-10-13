<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Enrolment\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\AcademicDirectory;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\EnrolmentEligibility;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\LevelInfo;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\ProgramInfo;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\RegulationBook;
use Scholaris\Modules\Apps\Higher\Admissions\Contracts\AdmissionDirectory;
use Scholaris\Modules\Apps\Higher\Billing\Contracts\FinancialHolds;
use Scholaris\Modules\Apps\Higher\Curriculum\Contracts\CurriculumDirectory;
use Scholaris\Modules\Apps\Higher\Enrolment\Domain\EnrolmentStatus;
use Scholaris\Modules\Apps\Higher\Enrolment\Domain\ProgressionRules;
use Scholaris\Modules\Apps\Higher\Enrolment\Domain\RefundGrid;
use Scholaris\Modules\Apps\Higher\Enrolment\Infrastructure\EnrolmentStore;
use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitSettingsReader;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;

/**
 * Inscriptions administratives IPES (EX-INS-01 à 05, T235-T237, T240, T416-T421) :
 *  - depuis une candidature « admis » (dossier étudiant, conversion T417) ou en réinscription (RG-INS-3 : décision « admis » ou
 *    « admis avec dettes » de l'année N ; « redouble » pour le même niveau) ;
 *  - garde IPES (autorisation MINESUP du niveau, tutelle LMD : RG-ACA-1/2), règlement figé à la première inscription (RG-ACA-3),
 *    version de maquette mémorisée (RG-MAQ-1), contrat pédagogique avec dettes et UE capitalisées (RG-INS-4) ;
 *  - limite d'inscriptions dans un niveau (RG-INS-5, défaut 2, dérogation R04) ; double cursus (RG-ORG-6, paramètre D7) ;
 *  - validation si frais d'inscription payés, ou « sous réserve financière » sur décision de R04 (RG-INS-2) ; matricule (RG-INS-1) et
 *    NOT-04 ; statuts (EX-INS-04), abandon et remboursement selon la grille (RG-INS-6) ; changement de filière, groupe, mode.
 */
final class EnrolmentService
{
    public const TABLE = 'hi_enrolments';
    public const EVENTS = 'hi_enrolment_events';
    public const DECISIONS = 'hi_progression_decisions';
    public const DEROGATIONS = 'hi_enrolment_derogations';
    public const CONSECUTIVE = 'consecutive_limit';

    public function __construct(
        private readonly EnrolmentStore $store,
        private readonly EnrolmentAccess $access,
        private readonly AcademicDirectory $academic,
        private readonly EnrolmentEligibility $eligibility,
        private readonly RegulationBook $regulations,
        private readonly CurriculumDirectory $curricula,
        private readonly AdmissionDirectory $admissions,
        private readonly FinancialHolds $holds,
        private readonly EnrollmentDirectory $enrollments,
        private readonly OrgUnitSettingsReader $settings,
        private readonly MatriculeService $matricules,
        private readonly LearningContractService $contracts,
        private readonly RegistrationFees $fees,
        private readonly StudentMessages $messages,
        private readonly EnrolmentClock $clock,
    ) {
    }

    /**
     * Inscription d'un candidat admis (EX-INS-01) : la candidature passe à « inscrite » ; équivalences de transfert capitalisées.
     *
     * @return array<string, mixed>
     */
    public function fromApplication(Principal $principal, string $establishmentId, string $applicationId, ?string $groupId, ?string $studyMode): array
    {
        $tenantId = $principal->tenantId;
        $application = $this->admissions->application($tenantId, $establishmentId, $applicationId) ?? throw HttpException::notFound('Candidature introuvable.');
        [, $level] = $this->structure($tenantId, $establishmentId, $application->levelId);
        $this->writableScope($principal, $establishmentId, $level, $groupId, [EnrolmentPermissions::ENROL]);
        if (!$application->isAdmitted()) {
            throw HttpException::conflict($application->status === 'conditional'
                ? 'Candidat admis sous réserve : la pièce exigée doit être vérifiée et la décision « admis » prise avant l\'inscription (RG-ADM-1).'
                : 'Seule une candidature « admis » donne lieu à une inscription.');
        }

        return $this->store->transaction(function () use ($principal, $establishmentId, $application, $groupId, $studyMode): array {
            $enrolment = $this->open($principal->tenantId, $establishmentId, $principal->userId, [
                'person_id' => $application->personId, 'level_id' => $application->levelId, 'group_id' => $groupId, 'study_mode' => $studyMode,
                'academic_year_id' => $application->academicYearId, 'kind' => $application->kind === 'transfer' ? 'transfer' : 'new',
                'application_id' => $application->id, 'capitalized' => $application->capitalizedUnitCodes, 'debts' => [],
            ]);
            $this->admissions->markEnrolled($principal->tenantId, $establishmentId, $application->id, (string) $enrolment['id'], $principal->userId);

            return $enrolment;
        });
    }

    /**
     * Réinscription (EX-INS-03, RG-INS-3/4) : décision de l'année N exigée, dettes inscrites d'office, UE capitalisées reportées ;
     * refusée si un blocage financier de réinscription est actif (RG-FIN-5, paramétré par Higher\Billing).
     *
     * @return array<string, mixed>
     */
    public function reenrol(Principal $principal, string $establishmentId, string $personId, string $levelId, string $academicYearId, ?string $groupId, ?string $studyMode): array
    {
        $tenantId = $principal->tenantId;
        [$program, $level] = $this->structure($tenantId, $establishmentId, $levelId);
        $this->writableScope($principal, $establishmentId, $level, $groupId, [EnrolmentPermissions::ENROL]);
        if ($this->holds->isBlocked($tenantId, $establishmentId, $personId, FinancialHolds::REINSCRIPTION)) {
            throw HttpException::validation(['person_id' => ['Réinscription bloquée pour impayés (RG-FIN-5) : régularisez le compte de scolarité.']]);
        }
        $context = $this->reenrolmentContext($tenantId, $establishmentId, $personId, $program, $level, $academicYearId);

        return $this->store->transaction(fn (): array => $this->open($tenantId, $establishmentId, $principal->userId, [
            'person_id' => $personId, 'level_id' => $levelId, 'group_id' => $groupId, 'study_mode' => $studyMode, 'academic_year_id' => $academicYearId,
            'kind' => 'reenrolment', 'application_id' => null, 'capitalized' => $context['capitalized'], 'debts' => $context['debts'],
        ]));
    }

    /**
     * Passage de fin d'année (D8, planificateur Higher) : inscription « en attente » de N+1 pour chaque décision de N qui l'autorise,
     * sans blocage ni dépassement de la limite RG-INS-5 sans dérogation. Idempotent.
     *
     * @return array{created: int, skipped: int}
     */
    public function promote(string $tenantId, string $establishmentId, string $fromYearId, string $toYearId): array
    {
        $created = $skipped = 0;
        foreach ($this->store->select(self::DECISIONS, $tenantId, $establishmentId, ['academic_year_id' => $fromYearId], 'person_id, id', 100000) as $decision) {
            $current = $this->academic->level($tenantId, $establishmentId, (string) $decision['level_id']);
            if ($current === null || $decision['decision'] === ProgressionRules::EXCLUDED) {
                ++$skipped;
                continue;
            }
            $target = $current;
            if ($decision['decision'] !== ProgressionRules::REPEAT) {
                $target = null;
                foreach ($this->academic->levelsOf($tenantId, $establishmentId, $current->programId) as $candidate) {
                    if ($candidate->ordinal === $current->ordinal + 1) {
                        $target = $candidate;
                    }
                }
            }
            if ($target === null || $this->liveIn($tenantId, $establishmentId, (string) $decision['person_id'], $toYearId, $current->programId) !== null) {
                ++$skipped;
                continue;
            }
            try {
                [$program, $level] = $this->structure($tenantId, $establishmentId, $target->id);
                $context = $this->reenrolmentContext($tenantId, $establishmentId, (string) $decision['person_id'], $program, $level, $toYearId);
                $this->store->transaction(fn (): array => $this->open($tenantId, $establishmentId, null, [
                    'person_id' => (string) $decision['person_id'], 'level_id' => $target->id, 'group_id' => null, 'study_mode' => null,
                    'academic_year_id' => $toYearId, 'kind' => 'reenrolment', 'application_id' => null,
                    'capitalized' => $context['capitalized'], 'debts' => $context['debts'],
                ]));
                ++$created;
            } catch (HttpException) {
                ++$skipped;
            }
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * Validation de l'inscription administrative (RG-INS-2) : frais d'inscription payés, sinon « sous réserve financière » sur décision
     * motivée du directeur d'école (`higher.enrolment.validate`). Attribue le matricule (RG-INS-1) et envoie NOT-04. Rappelée sur une
     * inscription sous réserve dont les frais sont désormais payés, elle lève la réserve.
     *
     * @return array<string, mixed>
     */
    public function validate(Principal $principal, string $establishmentId, string $id, bool $financialHold, ?string $reason): array
    {
        $tenantId = $principal->tenantId;
        $enrolment = $this->writable($principal, $establishmentId, $id, [EnrolmentPermissions::ENROL, EnrolmentPermissions::VALIDATE]);
        if (!in_array($enrolment['status'], [EnrolmentStatus::PENDING, EnrolmentStatus::FINANCIAL_HOLD], true)) {
            throw HttpException::conflict('Seule une inscription en attente ou sous réserve financière peut être validée.');
        }
        $fees = $this->fees->status($tenantId, $establishmentId, (string) $enrolment['person_id'], (string) $enrolment['academic_year_id'], (string) $enrolment['program_id']);
        if ($fees['paid']) {
            $status = EnrolmentStatus::ENROLLED;
        } elseif ($financialHold && $enrolment['status'] === EnrolmentStatus::PENDING) {
            $this->writable($principal, $establishmentId, $id, [EnrolmentPermissions::VALIDATE]);
            if ($reason === null) {
                throw HttpException::validation(['reason' => ['Motif obligatoire pour une inscription sous réserve financière.']]);
            }
            $status = EnrolmentStatus::FINANCIAL_HOLD;
        } else {
            throw HttpException::validation(
                ['fees' => [$fees['account_id'] === null
                ? 'Aucun compte de scolarité ouvert pour cette année et cette filière : frais d\'inscription non payés (RG-INS-2).'
                : sprintf('Frais d\'inscription non payés : %d FCFA restants (RG-INS-2).', $fees['registration_due_xaf'])]],
                'Inscription non validée : frais d\'inscription non payés ; seul le directeur d\'école peut inscrire « sous réserve financière ».',
            );
        }
        [$program] = $this->structure($tenantId, $establishmentId, (string) $enrolment['level_id']);
        $matricule = $this->matricules->assign($tenantId, $establishmentId, (string) $enrolment['person_id'], $program, (int) $this->clock->at()->format('Y'));
        $changes = ['status' => $status, 'updated_at' => $this->clock->now()];
        if ($enrolment['validated_at'] === null) {
            $changes += ['validated_by' => $principal->userId, 'validated_at' => $this->clock->now()];
        }
        if ($status === EnrolmentStatus::FINANCIAL_HOLD) {
            $changes += ['financial_hold' => 1, 'financial_hold_by' => $principal->userId, 'financial_hold_reason' => $reason];
        } else {
            $changes += ['financial_hold' => 0];
        }
        $this->store->update(self::TABLE, $tenantId, $establishmentId, $changes, ['id' => $id]);
        $this->event($tenantId, $establishmentId, $enrolment, $status === EnrolmentStatus::FINANCIAL_HOLD ? 'financial_hold' : 'validated', (string) $enrolment['status'], $status, $reason, $principal->userId);
        $this->access->audit($tenantId, $principal->userId, 'higher.enrolment.validated', 'hi_enrolment', $id, ['status' => $enrolment['status']], ['status' => $status, 'matricule' => $matricule, 'reason' => $reason]);
        if ($enrolment['status'] === EnrolmentStatus::PENDING) {
            $this->messages->validated($enrolment, $matricule);
        }

        return $this->get($tenantId, $establishmentId, $id);
    }

    /**
     * Statuts EX-INS-04 : suspendu (gel), abandon (remboursement selon la grille de l'école avant la date limite, RG-INS-6), exclu,
     * transféré, diplômé, réintégré. Motif obligatoire.
     *
     * @return array<string, mixed>
     */
    public function changeStatus(Principal $principal, string $establishmentId, string $id, string $status, string $reason): array
    {
        $tenantId = $principal->tenantId;
        $enrolment = $this->writable($principal, $establishmentId, $id, [EnrolmentPermissions::ENROL, EnrolmentPermissions::VALIDATE]);
        if (!EnrolmentStatus::canMove((string) $enrolment['status'], $status)) {
            throw HttpException::conflict(sprintf('Passage du statut « %s » à « %s » impossible.', $enrolment['status'], $status));
        }
        $changes = ['status' => $status, 'status_reason' => $reason, 'updated_at' => $this->clock->now()];
        if ($status === EnrolmentStatus::WITHDRAWN) {
            $today = $this->clock->today();
            $school = (string) $enrolment['school_id'];
            $grid = RefundGrid::fromSettings(
                $this->settings->valueForOrgUnit($tenantId, $establishmentId, $school, EnrolmentPermissions::WITHDRAWAL_DEADLINE),
                $this->settings->valueForOrgUnit($tenantId, $establishmentId, $school, EnrolmentPermissions::REFUND_GRID, []),
            );
            $paid = $this->fees->status($tenantId, $establishmentId, (string) $enrolment['person_id'], (string) $enrolment['academic_year_id'], (string) $enrolment['program_id'])['refundable_paid_xaf'];
            $changes += ['withdrawn_on' => $today, 'refund_percent' => $grid->percentOn($today), 'refund_xaf' => $grid->refundXaf($today, $paid)];
        }
        $this->store->update(self::TABLE, $tenantId, $establishmentId, $changes, ['id' => $id]);
        $this->event($tenantId, $establishmentId, $enrolment, 'status', (string) $enrolment['status'], $status, $reason, $principal->userId);
        $this->access->audit($tenantId, $principal->userId, 'higher.enrolment.status', 'hi_enrolment', $id, ['status' => $enrolment['status']], array_diff_key($changes, ['updated_at' => 1]));

        return $this->get($tenantId, $establishmentId, $id);
    }

    /**
     * Changement de filière (nouveau niveau), de groupe ou de mode (EX-INS-05, Should). Le matricule ne change jamais (RG-INS-1) ; un
     * changement de filière reconstruit le contrat pédagogique sur la maquette de la nouvelle filière ; les frais sont à recalculer par
     * la comptabilité (`fees_recalculation_required`).
     *
     * @return array<string, mixed>
     */
    public function change(Principal $principal, string $establishmentId, string $id, ?string $levelId, ?string $groupId, ?string $studyMode, string $reason): array
    {
        $tenantId = $principal->tenantId;
        $enrolment = $this->writable($principal, $establishmentId, $id, [EnrolmentPermissions::ENROL]);
        if (!in_array($enrolment['status'], [EnrolmentStatus::PENDING, EnrolmentStatus::ENROLLED, EnrolmentStatus::FINANCIAL_HOLD], true)) {
            throw HttpException::conflict('Seule une inscription en cours peut changer de filière, de groupe ou de mode.');
        }
        $changes = [];
        $targetLevelId = $levelId ?? (string) $enrolment['level_id'];
        [$program, $level] = $this->structure($tenantId, $establishmentId, $targetLevelId);
        $levelChanged = $levelId !== null && $levelId !== $enrolment['level_id'];
        if ($levelChanged) {
            $this->writableScope($principal, $establishmentId, $level, $groupId, [EnrolmentPermissions::ENROL]);
            $this->assertEligible($tenantId, $establishmentId, $groupId ?? $level->id, (string) $enrolment['academic_year_id']);
            if ($program->id !== $enrolment['program_id']) {
                $this->assertNoOtherLive($tenantId, $establishmentId, (string) $enrolment['person_id'], (string) $enrolment['academic_year_id'], $program->id, $id, (string) $program->schoolId);
            }
            $curriculum = $this->curricula->publishedFor($tenantId, $establishmentId, $program->id, $level->id, (string) $enrolment['academic_year_id']);
            $changes += [
                'school_id' => $program->schoolId, 'department_id' => $program->departmentId, 'program_id' => $program->id, 'level_id' => $level->id,
                'group_id' => null, 'curriculum_id' => $curriculum?->id, 'curriculum_version' => $curriculum?->version,
            ];
        }
        if ($groupId !== null) {
            $changes['group_id'] = $this->groupOf($tenantId, $establishmentId, $level, $groupId);
        }
        if ($studyMode !== null) {
            $changes['study_mode'] = $studyMode;
        }
        if ($changes === []) {
            throw HttpException::validation(['level_id' => ['Indiquez une nouvelle filière (niveau), un groupe ou un mode.']]);
        }
        $changes['updated_at'] = $this->clock->now();
        $this->store->transaction(function () use ($tenantId, $establishmentId, $id, $enrolment, $changes, $reason, $principal, $levelChanged): void {
            $this->store->update(self::TABLE, $tenantId, $establishmentId, $changes, ['id' => $id]);
            foreach (['program_id' => 'program', 'group_id' => 'group', 'study_mode' => 'mode'] as $column => $kind) {
                if (array_key_exists($column, $changes) && $changes[$column] !== $enrolment[$column]) {
                    $this->event($tenantId, $establishmentId, $enrolment, 'change_' . $kind, isset($enrolment[$column]) ? (string) $enrolment[$column] : null, isset($changes[$column]) ? (string) $changes[$column] : null, $reason, $principal->userId);
                }
            }
            if ($levelChanged) {
                $this->contracts->build($this->get($tenantId, $establishmentId, $id), [], []);
            }
        });
        $this->access->audit($tenantId, $principal->userId, 'higher.enrolment.changed', 'hi_enrolment', $id, array_intersect_key($enrolment, $changes), array_diff_key($changes, ['updated_at' => 1]));

        return $this->get($tenantId, $establishmentId, $id) + ['fees_recalculation_required' => $levelChanged || $studyMode !== null];
    }

    /**
     * Lien avec l'inscription du socle (People, `enrollments`) : même personne, établissement et année.
     *
     * @return array<string, mixed>
     */
    public function link(Principal $principal, string $establishmentId, string $id, string $enrollmentId): array
    {
        $enrolment = $this->writable($principal, $establishmentId, $id, [EnrolmentPermissions::ENROL]);
        $socle = $this->enrollments->find($principal->tenantId, $enrollmentId);
        if ($socle === null || $socle->establishmentId !== $establishmentId || $socle->personId !== $enrolment['person_id'] || $socle->academicYearId !== $enrolment['academic_year_id']) {
            throw HttpException::validation(['enrollment_id' => ['Inscription du socle introuvable pour cette personne, cet établissement et cette année.']]);
        }
        $this->store->update(self::TABLE, $principal->tenantId, $establishmentId, ['enrollment_id' => $enrollmentId, 'updated_at' => $this->clock->now()], ['id' => $id]);
        $this->access->audit($principal->tenantId, $principal->userId, 'higher.enrolment.linked', 'hi_enrolment', $id, null, ['enrollment_id' => $enrollmentId]);

        return $this->get($principal->tenantId, $establishmentId, $id);
    }

    /**
     * Documents de l'inscription : carte d'étudiant avec QR (DOC-05, inscription validée) ou fiche d'inscription et contrat
     * pédagogique (DOC-04).
     *
     * @return array<string, mixed>
     */
    public function issue(Principal $principal, string $establishmentId, string $id, string $code): array
    {
        $tenantId = $principal->tenantId;
        $enrolment = $this->writable($principal, $establishmentId, $id, [EnrolmentPermissions::ENROL]);
        if (!in_array($enrolment['status'], [EnrolmentStatus::ENROLLED, EnrolmentStatus::FINANCIAL_HOLD], true)) {
            throw HttpException::conflict('Document réservé à une inscription validée.');
        }
        $matricule = $enrolment['matricule'] ?? null;
        if ($code === 'DOC-05') {
            $document = $this->messages->issue('DOC-05', $enrolment, is_string($matricule) ? $matricule : null, ['valid_until' => $this->clock->plusDays(365)], $principal->userId);
            $column = 'card_document_id';
        } else {
            $units = array_map(static fn (array $i): string => $i['unit_code'] . ' ' . $i['unit_title'] . ' (' . $i['credits'] . ' cr., ' . $i['origin'] . ')', $this->contracts->show($enrolment)['items']);
            $modes = ['day' => 'jour', 'evening' => 'soir', 'work_study' => 'alternance'];
            $document = $this->messages->issue('DOC-04', $enrolment, is_string($matricule) ? $matricule : null, [
                'study_mode' => $modes[(string) $enrolment['study_mode']] ?? (string) $enrolment['study_mode'], 'units_list' => implode(' ; ', $units),
            ], $principal->userId);
            $column = 'form_document_id';
        }
        $this->store->update(self::TABLE, $tenantId, $establishmentId, [$column => isset($document['id']) ? (string) $document['id'] : null, 'updated_at' => $this->clock->now()], ['id' => $id]);
        $this->access->audit($tenantId, $principal->userId, 'higher.enrolment.document', 'hi_enrolment', $id, null, ['code' => $code, 'document_id' => $document['id'] ?? null]);

        return $document;
    }

    /**
     * Inscription avec le matricule de l'étudiant.
     *
     * @return array<string, mixed>
     */
    public function get(string $tenantId, string $establishmentId, string $id): array
    {
        $row = $this->store->findOrFail(self::TABLE, $tenantId, $establishmentId, $id, 'Inscription introuvable.');
        $student = $this->store->find(MatriculeService::STUDENTS, $tenantId, $establishmentId, (string) $row['student_id']);
        $row['matricule'] = $student['matricule'] ?? null;

        return $row;
    }

    /**
     * @param list<string> $permissions
     * @return array<string, mixed>
     */
    public function writable(Principal $principal, string $establishmentId, string $id, array $permissions): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, $permissions);
        $row = $this->get($principal->tenantId, $establishmentId, $id);
        $this->access->assertWritable($principal, $perimeter, $row, 'hi_enrolment', $id);

        return $row;
    }

    /**
     * @param array{person_id: string, level_id: string, group_id: ?string, study_mode: ?string, academic_year_id: string, kind: string,
     *              application_id: ?string, capitalized: list<string>, debts: list<array{code: string, title: string, credits: int, semester: ?int, level_id: ?string, unit_id: ?string}>} $ctx
     * @return array<string, mixed>
     */
    private function open(string $tenantId, string $establishmentId, ?string $actorId, array $ctx): array
    {
        [$program, $level] = $this->structure($tenantId, $establishmentId, $ctx['level_id']);
        $groupId = $ctx['group_id'] === null ? null : $this->groupOf($tenantId, $establishmentId, $level, $ctx['group_id']);
        $year = $ctx['academic_year_id'];
        $this->assertEligible($tenantId, $establishmentId, $groupId ?? $level->id, $year);
        $this->assertNoOtherLive($tenantId, $establishmentId, $ctx['person_id'], $year, $program->id, null, $program->schoolId);
        [$attempt, $derogationId] = $this->attempt($tenantId, $establishmentId, $ctx['person_id'], $program, $level, $year);
        $curriculum = $this->curricula->publishedFor($tenantId, $establishmentId, $program->id, $level->id, $year);
        $student = $this->matricules->ensureStudent($tenantId, $establishmentId, $ctx['person_id'], $program);
        $this->regulations->freezeForEnrolment($tenantId, $establishmentId, $program->schoolId, $program->regime, $year, $actorId);
        $id = (string) new Ulid();
        $now = $this->clock->now();
        $this->store->insert(self::TABLE, $tenantId, $establishmentId, [
            'id' => $id, 'student_id' => $student['id'], 'person_id' => $ctx['person_id'], 'enrollment_id' => null, 'application_id' => $ctx['application_id'],
            'academic_year_id' => $year, 'school_id' => $program->schoolId, 'department_id' => $program->departmentId, 'program_id' => $program->id,
            'level_id' => $level->id, 'group_id' => $groupId, 'study_mode' => $ctx['study_mode'] ?? $program->studyMode, 'enrolment_kind' => $ctx['kind'],
            'curriculum_id' => $curriculum?->id, 'curriculum_version' => $curriculum?->version, 'attempt_number' => $attempt, 'derogation_id' => $derogationId,
            'status' => EnrolmentStatus::PENDING, 'status_reason' => null, 'financial_hold' => 0, 'created_by' => $actorId, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $enrolment = $this->get($tenantId, $establishmentId, $id);
        $this->contracts->build($enrolment, $ctx['capitalized'], $ctx['debts']);
        $this->event($tenantId, $establishmentId, $enrolment, 'created', null, EnrolmentStatus::PENDING, $ctx['kind'], $actorId);
        $this->access->audit($tenantId, $actorId, 'higher.enrolment.created', 'hi_enrolment', $id, null, [
            'person_id' => $ctx['person_id'], 'level_id' => $level->id, 'academic_year_id' => $year, 'kind' => $ctx['kind'], 'attempt_number' => $attempt,
            'curriculum_id' => $curriculum?->id, 'derogation_id' => $derogationId,
        ]);

        return $enrolment;
    }

    /**
     * Décision de N et report : UE acquises du même niveau capitalisées (redoublement), UE manquantes inscrites en dette (passage
     * avec dettes). RG-INS-3 : refus motivé sinon.
     *
     * @return array{capitalized: list<string>, debts: list<array{code: string, title: string, credits: int, semester: ?int, level_id: ?string, unit_id: ?string}>}
     */
    private function reenrolmentContext(string $tenantId, string $establishmentId, string $personId, ProgramInfo $program, LevelInfo $level, string $academicYearId): array
    {
        $decision = null;
        foreach ($this->store->select(self::DECISIONS, $tenantId, $establishmentId, ['person_id' => $personId, 'program_id' => $program->id], 'recorded_at DESC, id DESC', 50) as $row) {
            if ($row['academic_year_id'] !== $academicYearId) {
                $decision = $row;
                break;
            }
        }
        $previous = $decision === null ? null : $this->academic->level($tenantId, $establishmentId, (string) $decision['level_id']);
        $refusal = ProgressionRules::refusal($decision === null ? null : (string) $decision['decision'], $previous->ordinal ?? 0, $level->ordinal);
        if ($refusal !== null && !($decision !== null && $decision['decision'] === ProgressionRules::EXCLUDED && $previous?->id === $level->id
            && $this->derogation($tenantId, $establishmentId, $personId, $level->id, $academicYearId) !== null)) {
            throw HttpException::validation(['level_id' => [$refusal]], 'Réinscription refusée : ' . $refusal);
        }
        $acquired = self::codes($decision['acquired_units_json'] ?? '[]');
        $missing = self::codes($decision['missing_units_json'] ?? '[]');
        if ($decision === null || $previous === null || $previous->id === $level->id) {
            return ['capitalized' => $acquired, 'debts' => []];
        }
        $units = [];
        foreach ($this->store->select(self::TABLE, $tenantId, $establishmentId, ['person_id' => $personId, 'level_id' => $previous->id, 'academic_year_id' => (string) $decision['academic_year_id']], 'created_at DESC', 1) as $previousEnrolment) {
            foreach ($this->contracts->unitsOf($tenantId, $establishmentId, isset($previousEnrolment['curriculum_id']) ? (string) $previousEnrolment['curriculum_id'] : null) as $unit) {
                $units[$unit->code] = $unit;
            }
        }
        $debts = [];
        foreach ($missing as $code) {
            $unit = $units[$code] ?? null;
            $debts[] = [
                'code' => $code, 'title' => $unit->title ?? $code, 'credits' => $unit->credits ?? 0, 'semester' => $unit?->semesterOrdinal,
                'level_id' => $previous->id, 'unit_id' => $unit?->id,
            ];
        }

        return ['capitalized' => [], 'debts' => $debts];
    }

    /**
     * RG-INS-5 : rang de l'inscription dans le niveau ; au-delà de la limite (défaut 2), une dérogation du directeur d'école est exigée.
     *
     * @return array{0: int, 1: ?string}
     */
    private function attempt(string $tenantId, string $establishmentId, string $personId, ProgramInfo $program, LevelInfo $level, string $year): array
    {
        $previous = 0;
        foreach ($this->store->select(self::TABLE, $tenantId, $establishmentId, ['person_id' => $personId, 'level_id' => $level->id], 'id', 1000) as $row) {
            if ($row['academic_year_id'] !== $year && in_array($row['status'], EnrolmentStatus::VALIDATED, true)) {
                ++$previous;
            }
        }
        $attempt = $previous + 1;
        $max = $this->settings->valueForOrgUnit($tenantId, $establishmentId, $program->schoolId, EnrolmentPermissions::MAX_CONSECUTIVE, ProgressionRules::DEFAULT_MAX_CONSECUTIVE);
        $max = is_int($max) && $max > 0 ? $max : ProgressionRules::DEFAULT_MAX_CONSECUTIVE;
        if ($attempt <= $max) {
            return [$attempt, null];
        }
        $derogation = $this->derogation($tenantId, $establishmentId, $personId, $level->id, $year);
        if ($derogation === null) {
            throw HttpException::validation(['level_id' => [sprintf(
                '%de inscription dans le niveau %s : au-delà de %d inscriptions, une dérogation du directeur d\'école est exigée (RG-INS-5).',
                $attempt,
                $level->name,
                $max,
            )]], 'Limite d\'inscriptions dans le niveau atteinte (RG-INS-5).');
        }

        return [$attempt, (string) $derogation['id']];
    }

    /** @return array<string, mixed>|null */
    private function derogation(string $tenantId, string $establishmentId, string $personId, string $levelId, string $year): ?array
    {
        return $this->store->select(self::DEROGATIONS, $tenantId, $establishmentId, [
            'person_id' => $personId, 'level_id' => $levelId, 'academic_year_id' => $year, 'derogation_kind' => self::CONSECUTIVE,
        ], 'id', 1)[0] ?? null;
    }

    /** Double cursus (RG-ORG-6, D7) : une inscription vivante par filière et par année, plusieurs filières seulement si permis. */
    private function assertNoOtherLive(string $tenantId, string $establishmentId, string $personId, string $year, string $programId, ?string $exceptId, string $schoolId): void
    {
        foreach ($this->store->select(self::TABLE, $tenantId, $establishmentId, ['person_id' => $personId, 'academic_year_id' => $year], 'id', 100) as $row) {
            if ($row['id'] === $exceptId || !in_array($row['status'], EnrolmentStatus::LIVE, true)) {
                continue;
            }
            if ($row['program_id'] === $programId) {
                throw HttpException::conflict('Cette personne a déjà une inscription en cours dans cette filière pour cette année.');
            }
            if ($this->settings->valueForOrgUnit($tenantId, $establishmentId, $schoolId, EnrolmentPermissions::MULTIPLE_TRACKS, false) !== true) {
                throw HttpException::conflict('Double cursus non autorisé dans cet établissement (paramètre enrolment.allow_multiple_tracks) : une inscription est déjà en cours cette année.');
            }
        }
    }

    /** @return array<string, mixed>|null */
    private function liveIn(string $tenantId, string $establishmentId, string $personId, string $year, string $programId): ?array
    {
        foreach ($this->store->select(self::TABLE, $tenantId, $establishmentId, ['person_id' => $personId, 'academic_year_id' => $year, 'program_id' => $programId], 'id', 10) as $row) {
            if (in_array($row['status'], EnrolmentStatus::LIVE, true)) {
                return $row;
            }
        }

        return null;
    }

    private function assertEligible(string $tenantId, string $establishmentId, string $structureId, string $year): void
    {
        $refusal = $this->eligibility->refusalFor($tenantId, $establishmentId, $structureId, $year, $this->clock->at());
        if ($refusal !== null) {
            throw HttpException::validation(['level_id' => [$refusal]], 'Inscription refusée : ' . $refusal);
        }
    }

    /** @return array{0: ProgramInfo, 1: LevelInfo} */
    private function structure(string $tenantId, string $establishmentId, string $levelId): array
    {
        $level = $this->academic->level($tenantId, $establishmentId, $levelId) ?? throw HttpException::validation(['level_id' => ['Niveau introuvable dans cet établissement.']]);
        $program = $this->academic->program($tenantId, $establishmentId, $level->programId) ?? throw HttpException::validation(['level_id' => ['Filière introuvable.']]);

        return [$program, $level];
    }

    private function groupOf(string $tenantId, string $establishmentId, LevelInfo $level, string $groupId): string
    {
        $group = $this->academic->group($tenantId, $establishmentId, $groupId);
        if ($group === null || $group->levelId !== $level->id) {
            throw HttpException::validation(['group_id' => ['Groupe introuvable dans ce niveau.']]);
        }

        return $group->id;
    }

    /** @param list<string> $permissions */
    private function writableScope(Principal $principal, string $establishmentId, LevelInfo $level, ?string $groupId, array $permissions): void
    {
        [$program] = $this->structure($principal->tenantId, $establishmentId, $level->id);
        $perimeter = $this->access->require($principal, $establishmentId, $permissions);
        $this->access->assertWritable($principal, $perimeter, [
            'school_id' => $program->schoolId, 'department_id' => $program->departmentId, 'program_id' => $program->id, 'level_id' => $level->id, 'group_id' => $groupId,
        ], 'hi_enrolment', $level->id);
    }

    /** @param array<string, mixed> $enrolment */
    private function event(string $tenantId, string $establishmentId, array $enrolment, string $type, ?string $from, ?string $to, ?string $reason, ?string $actorId): void
    {
        $this->store->insert(self::EVENTS, $tenantId, $establishmentId, [
            'id' => (string) new Ulid(), 'enrolment_id' => $enrolment['id'], 'person_id' => $enrolment['person_id'], 'event_type' => $type,
            'from_value' => $from, 'to_value' => $to, 'reason' => $reason, 'actor_id' => $actorId, 'occurred_at' => $this->clock->now(),
        ]);
    }

    /** @return list<string> */
    private static function codes(mixed $json): array
    {
        $decoded = json_decode((string) $json, true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }
}
