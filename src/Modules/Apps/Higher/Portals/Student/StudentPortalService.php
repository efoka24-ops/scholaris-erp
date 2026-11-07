<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Portals\Student;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Higher\Billing\Contracts\StudentAccounts;
use Scholaris\Modules\Apps\Higher\Billing\Contracts\StudentAccountView;
use Scholaris\Modules\Apps\Higher\Billing\Contracts\StudentPayments;
use Scholaris\Modules\Apps\Higher\Documents\Contracts\IssuedDocuments;
use Scholaris\Modules\Socle\Identity\Contracts\UserPersonLink;
use Scholaris\Modules\Socle\People\Contracts\ConsentChecker;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/**
 * Portail étudiant et garant de l'IPES (POR, T241, T317) : candidature, inscription, paiement, reçus, documents ; emploi du temps
 * quand le module EDT le fournira. Le garant voit toujours la situation financière de ses étudiants et ne voit absences et notes
 * qu'avec le consentement de l'étudiant (RG-INS-7). Périmètre « soi » : la personne liée au compte connecté.
 */
final class StudentPortalService
{
    public const SELF = 'self.read';

    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly UserPersonLink $links,
        private readonly PersonDirectory $people,
        private readonly EnrollmentDirectory $enrollments,
        private readonly ConsentChecker $consents,
        private readonly StudentAccounts $accounts,
        private readonly StudentPayments $payments,
        private readonly IssuedDocuments $documents,
    ) {
    }

    /** @return array<string, mixed> */
    public function student(Principal $principal, string $establishmentId): array
    {
        $personId = $this->personOf($principal, $establishmentId);
        $person = $this->people->find($principal->tenantId, $personId) ?? throw HttpException::notFound('Fiche introuvable.');

        return [
            'person_id' => $personId,
            'full_name' => $person->fullName(),
            'application' => ['available' => false, 'reason' => 'La candidature en ligne est servie par le module Admissions.'],
            'enrolments' => $this->enrolments($principal->tenantId, $personId),
            'finance' => $this->finance($principal->tenantId, $personId),
            'receipts' => $this->receipts($principal->tenantId, $personId),
            'documents' => $this->documents->forPerson($principal->tenantId, $personId),
            'document_requests' => ['path' => '/api/v1/document-requests', 'always_available' => ['DOC-06', 'DOC-27', 'DOC-28', 'DOC-29']],
            'timetable' => ['available' => false, 'reason' => 'L\'emploi du temps sera affiché quand le module EDT sera ouvert.'],
        ];
    }

    /** @return array<string, mixed> */
    public function guarantor(Principal $principal, string $establishmentId): array
    {
        $guarantorId = $this->personOf($principal, $establishmentId);
        $students = [];
        foreach ($this->people->learnersOf($principal->tenantId, $guarantorId) as $learner) {
            if ($learner->tenantId !== $principal->tenantId) {
                continue;
            }
            $consent = $this->consents->hasConsent($principal->tenantId, $learner->id, ConsentChecker::PARENT_ACCESS);
            $students[] = [
                'person_id' => $learner->id,
                'full_name' => $learner->fullName(),
                'enrolments' => $this->enrolments($principal->tenantId, $learner->id),
                // Situation financière : toujours visible pour le garant (RG-INS-7).
                'finance' => $this->finance($principal->tenantId, $learner->id),
                'receipts' => $this->receipts($principal->tenantId, $learner->id),
                'consent' => $consent,
                'attendance' => $consent ? ['available' => false, 'reason' => 'Les absences seront affichées quand le module PRE sera ouvert.'] : ['available' => false, 'reason' => 'consent_required'],
                'grades' => $consent ? ['available' => false, 'reason' => 'Les notes publiées seront affichées quand le module NOT sera ouvert.'] : ['available' => false, 'reason' => 'consent_required'],
            ];
        }

        return ['guarantor_id' => $guarantorId, 'students' => $students];
    }

    /**
     * Paiement mobile money d'un compte de l'étudiant connecté ou d'un étudiant dont le connecté est garant.
     *
     * @return array<string, mixed>
     */
    public function pay(Principal $principal, string $establishmentId, ?string $studentId, string $accountId, int $amount, string $channel, string $phone, string $key): array
    {
        $personId = $this->personOf($principal, $establishmentId);
        $target = $personId;
        if ($studentId !== null && $studentId !== $personId) {
            $linked = array_filter($this->people->learnersOf($principal->tenantId, $personId), static fn ($l): bool => $l->id === $studentId);
            if ($linked === []) {
                throw HttpException::notFound('Étudiant introuvable.');
            }
            $target = $studentId;
        }

        return $this->payments->requestMobilePayment($principal->tenantId, $target, $accountId, $amount, $channel, $phone, $key, $principal->userId);
    }

    /** @return list<array<string, mixed>> */
    private function finance(string $tenantId, string $personId): array
    {
        return array_map(static fn (StudentAccountView $a): array => [
            'account_id' => $a->id, 'academic_year_id' => $a->academicYearId, 'school_id' => $a->schoolId, 'program_id' => $a->programId,
            'net_xaf' => $a->netXaf, 'paid_xaf' => $a->paidXaf, 'balance_xaf' => $a->balanceXaf, 'up_to_date' => $a->upToDate,
            'payer_name' => $a->payerName, 'installments' => $a->installments,
        ], $this->accounts->forPerson($tenantId, $personId));
    }

    /** @return list<array<string, mixed>> */
    private function receipts(string $tenantId, string $personId): array
    {
        $out = [];
        foreach ($this->accounts->forPerson($tenantId, $personId) as $account) {
            foreach ($account->receipts as $receipt) {
                $out[] = $receipt + ['account_id' => $account->id];
            }
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function enrolments(string $tenantId, string $personId): array
    {
        $out = [];
        foreach ($this->enrollments->forPerson($tenantId, $personId) as $enrollment) {
            $out[] = [
                'id' => $enrollment->id, 'establishment_id' => $enrollment->establishmentId, 'structure_id' => $enrollment->structureId,
                'academic_year_id' => $enrollment->academicYearId, 'status' => $enrollment->status, 'matricule' => $enrollment->internalNumber,
            ];
        }

        return $out;
    }

    private function personOf(Principal $principal, string $establishmentId): string
    {
        if (!$this->permissions->can($principal, self::SELF, $establishmentId)) {
            throw HttpException::forbidden('Permission requise : self.read.');
        }

        return $this->links->personIdForUser($principal->tenantId, $principal->userId)
            ?? throw HttpException::forbidden('Votre compte n\'est rattaché à aucune personne : contactez l\'établissement.');
    }
}
