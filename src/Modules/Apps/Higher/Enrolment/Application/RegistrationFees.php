<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Enrolment\Application;

use Scholaris\Modules\Apps\Higher\Billing\Contracts\StudentAccounts;
use Scholaris\Modules\Socle\Billing\Contracts\InvoiceIssuer;

/**
 * État des frais d'une inscription (RG-INS-2, RG-INS-6), lu sur le compte de scolarité de l'année et de la filière (Higher\Billing)
 * et sa facture du socle : frais d'inscription (`hi.inscription`) soldés ou non, pension payée (seule part remboursable).
 * Sans compte de scolarité, rien n'est payé ; un compte sans frais d'inscription n'en exige pas.
 */
final class RegistrationFees
{
    public function __construct(
        private readonly StudentAccounts $accounts,
        private readonly InvoiceIssuer $invoices,
    ) {
    }

    /** @return array{account_id: ?string, paid: bool, registration_due_xaf: int, refundable_paid_xaf: int} */
    public function status(string $tenantId, string $establishmentId, string $personId, string $academicYearId, string $programId): array
    {
        foreach ($this->accounts->forPerson($tenantId, $personId) as $account) {
            if ($account->establishmentId !== $establishmentId || $account->academicYearId !== $academicYearId
                || ($account->programId !== null && $account->programId !== $programId)) {
                continue;
            }
            $invoice = $this->invoices->find($tenantId, $establishmentId, $account->invoiceId);
            if ($invoice === null) {
                continue;
            }
            $due = $invoice->balanceForCode(EnrolmentPermissions::REGISTRATION_FEE_CODE);
            $refundable = 0;
            foreach ($invoice->lines as $line) {
                if ($line['fee_code'] === EnrolmentPermissions::TUITION_FEE_CODE) {
                    $refundable += $line['paid_xaf'];
                }
            }

            return ['account_id' => $account->id, 'paid' => $due === 0, 'registration_due_xaf' => $due, 'refundable_paid_xaf' => $refundable];
        }

        return ['account_id' => null, 'paid' => false, 'registration_due_xaf' => 0, 'refundable_paid_xaf' => 0];
    }
}
