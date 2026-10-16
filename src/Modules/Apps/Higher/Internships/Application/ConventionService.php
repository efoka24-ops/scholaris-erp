<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Internships\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\AcademicDirectory;
use Scholaris\Modules\Apps\Higher\Enrolment\Contracts\StudentDirectory;
use Scholaris\Modules\Apps\Higher\Internships\Documents\InternshipDocumentTypes;
use Scholaris\Modules\Apps\Higher\Internships\Domain\ConventionStatus;
use Scholaris\Modules\Apps\Higher\Internships\Domain\DefenseReadiness;
use Scholaris\Modules\Apps\Higher\Internships\Infrastructure\InternshipStore;

/**
 * Conventions de stage (EX-STG-02 à 04) : création sur une inscription validée, modification en brouillon, émission de la
 * convention DOC-20 (passage en signature), signatures des trois parties (l'école par le directeur d'école), validation du sujet,
 * attestation de fin de stage, dépôt du mémoire, annulation motivée. Toute écriture est journalisée.
 */
final class ConventionService
{
    public const TABLE = 'hi_internships';
    public const EDITABLE = ['company_id', 'subject', 'starts_on', 'ends_on', 'company_tutor_name', 'company_tutor_email', 'company_tutor_phone', 'supervisor_person_id'];

    public function __construct(
        private readonly InternshipStore $store,
        private readonly InternshipAccess $access,
        private readonly InternshipClock $clock,
        private readonly StudentDirectory $students,
        private readonly AcademicDirectory $academic,
        private readonly InternshipDocuments $documents,
    ) {
    }

    /** @return array<string, mixed> convention lisible (404 hors périmètre) */
    public function readable(Principal $principal, string $establishmentId, string $id): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, InternshipAccess::READ);
        $row = $this->row($principal, $establishmentId, $id);
        $this->access->assertReadable($perimeter, $row);

        return $row;
    }

    /**
     * @param array<string, scalar|null> $data enrolment_id et champs EDITABLE
     * @return array<string, mixed>
     */
    public function create(Principal $principal, string $establishmentId, array $data): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, InternshipAccess::MANAGE);
        $tenantId = $principal->tenantId;
        $enrolment = $this->students->enrolment($tenantId, $establishmentId, (string) $data['enrolment_id'])
            ?? throw HttpException::validation(['enrolment_id' => ['Inscription introuvable.']]);
        $program = $this->academic->program($tenantId, $establishmentId, $enrolment->programId);
        $row = [
            'id' => (string) new Ulid(), 'enrolment_id' => $enrolment->id, 'person_id' => $enrolment->personId, 'academic_year_id' => $enrolment->academicYearId,
            'school_id' => $enrolment->schoolId, 'department_id' => $program?->departmentId, 'program_id' => $enrolment->programId,
            'level_id' => $enrolment->levelId, 'group_id' => $enrolment->groupId,
        ];
        $this->access->assertWritable($principal, $perimeter, $row, 'hi_internship', $enrolment->id);
        if (!$enrolment->isValidated()) {
            throw HttpException::conflict('L\'inscription n\'est pas validée : aucune convention de stage possible.');
        }
        foreach ($this->store->select(self::TABLE, $tenantId, $establishmentId, ['enrolment_id' => $enrolment->id]) as $existing) {
            if ($existing['status'] !== ConventionStatus::CANCELLED) {
                throw HttpException::conflict('Une convention de stage est déjà en cours pour cette inscription.');
            }
        }
        $this->checkFields($tenantId, $establishmentId, $data);
        unset($data['enrolment_id']);
        $now = $this->clock->now();
        $this->store->insert(self::TABLE, $tenantId, $establishmentId, $row + $data + [
            'supervisor_name' => $this->documents->nameOf($tenantId, (string) $data['supervisor_person_id']),
            'status' => ConventionStatus::DRAFT, 'created_by' => $principal->userId, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->access->audit($principal, 'higher.internship.created', 'hi_internship', $row['id'], null, $data);

        return $this->row($principal, $establishmentId, $row['id']);
    }

    /**
     * @param array<string, scalar|null> $data champs EDITABLE fournis
     * @return array<string, mixed>
     */
    public function update(Principal $principal, string $establishmentId, string $id, array $data): array
    {
        $row = $this->writable($principal, $establishmentId, $id, InternshipAccess::MANAGE);
        if ($row['status'] !== ConventionStatus::DRAFT) {
            throw HttpException::conflict('Seule une convention en brouillon est modifiable.');
        }
        $this->checkFields($principal->tenantId, $establishmentId, $data + $row);
        if (isset($data['supervisor_person_id'])) {
            $data['supervisor_name'] = $this->documents->nameOf($principal->tenantId, (string) $data['supervisor_person_id']);
        }

        return $this->save($principal, $establishmentId, $row, $data, 'higher.internship.updated');
    }

    /**
     * Émission de la convention DOC-20 : brouillon → en signature.
     *
     * @return array<string, mixed>
     */
    public function issueConvention(Principal $principal, string $establishmentId, string $id): array
    {
        $row = $this->writable($principal, $establishmentId, $id, InternshipAccess::MANAGE);
        $this->transition($row, ConventionStatus::PENDING);
        $company = $this->store->findOrFail(CompanyService::TABLE, $principal->tenantId, $establishmentId, (string) $row['company_id']);
        $documentId = $this->documents->issue($principal->tenantId, $establishmentId, InternshipDocumentTypes::CONVENTION, $row, InternshipDocuments::internshipData($row, $company), $principal->userId);

        return $this->save($principal, $establishmentId, $row, ['status' => ConventionStatus::PENDING, 'convention_document_id' => $documentId], 'higher.internship.convention_issued');
    }

    /**
     * Signature d'une partie (student, company, school) ; les trois signatures font passer la convention à « signée ».
     *
     * @return array<string, mixed>
     */
    public function sign(Principal $principal, string $establishmentId, string $id, string $party, string $signedOn): array
    {
        $row = $this->writable($principal, $establishmentId, $id, $party === 'school' ? InternshipAccess::SIGN_SCHOOL : InternshipAccess::MANAGE);
        if ($row['status'] !== ConventionStatus::PENDING) {
            throw HttpException::conflict('La convention n\'est pas en attente de signature (émettez d\'abord la convention DOC-20).');
        }
        $changes = [$party . '_signed_on' => $signedOn];
        if ($party === 'school') {
            $changes['school_signed_by'] = $principal->userId;
        }
        $after = $changes + $row;
        if ($after['student_signed_on'] !== null && $after['company_signed_on'] !== null && $after['school_signed_on'] !== null) {
            $changes['status'] = ConventionStatus::SIGNED;
        }

        return $this->save($principal, $establishmentId, $row, $changes, 'higher.internship.signed');
    }

    /**
     * EX-STG-04 : sujet de mémoire validé (responsable de filière ou des stages).
     *
     * @return array<string, mixed>
     */
    public function validateSubject(Principal $principal, string $establishmentId, string $id): array
    {
        $row = $this->writable($principal, $establishmentId, $id, InternshipAccess::MANAGE);
        if ($row['status'] === ConventionStatus::CANCELLED) {
            throw HttpException::conflict('Convention annulée.');
        }

        return $this->save($principal, $establishmentId, $row, ['subject_validated_at' => $this->clock->now(), 'subject_validated_by' => $principal->userId], 'higher.internship.subject_validated');
    }

    /**
     * Attestation de fin de stage reçue : signée → terminée.
     *
     * @return array<string, mixed>
     */
    public function complete(Principal $principal, string $establishmentId, string $id, string $receivedOn, ?string $reference): array
    {
        $row = $this->writable($principal, $establishmentId, $id, InternshipAccess::MANAGE);
        $this->transition($row, ConventionStatus::COMPLETED);

        return $this->save($principal, $establishmentId, $row, ['status' => ConventionStatus::COMPLETED, 'completion_received_on' => $receivedOn, 'completion_ref' => $reference], 'higher.internship.completed');
    }

    /**
     * Dépôt du mémoire (par le service ou par l'étudiant lui-même) : convention signée exigée.
     *
     * @param array<string, mixed> $row convention déjà contrôlée par l'appelant
     * @return array<string, mixed>
     */
    public function depositThesis(Principal $principal, string $establishmentId, array $row, string $title, string $reference): array
    {
        if (!ConventionStatus::isRunning((string) $row['status'])) {
            throw HttpException::conflict('Le mémoire ne peut être déposé qu\'une fois la convention signée.');
        }

        return $this->save($principal, $establishmentId, $row, ['thesis_title' => $title, 'thesis_ref' => $reference, 'thesis_deposited_at' => $this->clock->now()], 'higher.internship.thesis_deposited');
    }

    /** @return array<string, mixed> */
    public function cancel(Principal $principal, string $establishmentId, string $id, string $reason): array
    {
        $row = $this->writable($principal, $establishmentId, $id, InternshipAccess::MANAGE);
        $this->transition($row, ConventionStatus::CANCELLED);

        return $this->save($principal, $establishmentId, $row, ['status' => ConventionStatus::CANCELLED, 'cancel_reason' => $reason], 'higher.internship.cancelled');
    }

    /**
     * RG-STG-1 : conditions de programmation de la soutenance.
     *
     * @param array<string, mixed> $row
     * @return array<string, bool>
     */
    public function readiness(string $tenantId, array $row): array
    {
        return DefenseReadiness::check($row, $this->documents->quitusIssued($tenantId, (string) $row['person_id']));
    }

    /**
     * @param list<string> $permissions
     * @return array<string, mixed>
     */
    public function writable(Principal $principal, string $establishmentId, string $id, array $permissions): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, $permissions);
        $row = $this->row($principal, $establishmentId, $id);
        $this->access->assertWritable($principal, $perimeter, $row, 'hi_internship', $id);

        return $row;
    }

    /** @return array<string, mixed> */
    private function row(Principal $principal, string $establishmentId, string $id): array
    {
        return $this->store->findOrFail(self::TABLE, $principal->tenantId, $establishmentId, $id, 'Convention de stage introuvable.');
    }

    /** @param array<string, mixed> $row */
    private function transition(array $row, string $to): void
    {
        if (!ConventionStatus::allows((string) $row['status'], $to)) {
            throw HttpException::conflict(sprintf('Transition refusée : %s → %s.', $row['status'], $to));
        }
    }

    /** @param array<string, mixed> $data */
    private function checkFields(string $tenantId, string $establishmentId, array $data): void
    {
        $errors = [];
        if ($this->store->find(CompanyService::TABLE, $tenantId, $establishmentId, (string) $data['company_id']) === null) {
            $errors['company_id'][] = 'Entreprise d\'accueil introuvable.';
        }
        if ((string) $data['ends_on'] < (string) $data['starts_on']) {
            $errors['ends_on'][] = 'La fin du stage précède son début.';
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, scalar|null> $changes
     * @return array<string, mixed>
     */
    private function save(Principal $principal, string $establishmentId, array $row, array $changes, string $action): array
    {
        $this->store->update(self::TABLE, $principal->tenantId, $establishmentId, $changes + ['updated_at' => $this->clock->now()], ['id' => (string) $row['id']]);
        $this->access->audit($principal, $action, 'hi_internship', (string) $row['id'], ['status' => $row['status']], $changes);

        return $this->row($principal, $establishmentId, (string) $row['id']);
    }
}
