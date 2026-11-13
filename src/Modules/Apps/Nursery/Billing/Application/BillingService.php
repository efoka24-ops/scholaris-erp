<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Billing\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Nursery\Support\Access;
use Scholaris\Modules\Apps\Nursery\Support\Perimeter;
use Scholaris\Modules\Socle\Billing\Contracts\DebtorDirectory;
use Scholaris\Modules\Socle\Billing\Contracts\InvoiceIssuer;
use Scholaris\Modules\Socle\Billing\Contracts\InvoiceSummary;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;

/**
 * Frais de l'enfant (EX-MAT-28, 30) par le socle Billing : facture d'inscription (frais d'inscription et pension par tranches des
 * grilles de l'école, remise de fratrie du socle) et solde de la famille. Aucun montant, remise ni échéance n'est écrit ici. Aucun
 * blocage du carnet pour impayé (`nursery.progress_book.block_on_debt` vaut faux ; la règle de blocage attend MQ-10).
 */
final class BillingService
{
    public function __construct(
        private readonly InvoiceIssuer $invoices,
        private readonly DebtorDirectory $debtors,
        private readonly EnrollmentDirectory $enrollments,
        private readonly Access $access,
    ) {
    }

    /** @return array<string, mixed> */
    public function invoiceEnrolment(Principal $principal, Perimeter $perimeter, string $enrollmentId): array
    {
        $enrollment = $this->enrollments->find($principal->tenantId, $enrollmentId);
        if ($enrollment === null || $enrollment->establishmentId !== $perimeter->establishmentId) {
            throw HttpException::notFound('Inscription introuvable dans cet établissement.');
        }
        $this->access->assertWritable($principal, $perimeter, $enrollment->structureId, 'enrollment', $enrollmentId);
        $summary = $this->invoices->forEnrollment($principal->tenantId, $perimeter->establishmentId, $principal->userId, $enrollmentId);
        $this->access->audit($principal, 'nursery.enrolment_invoice.issued', 'invoice', $summary->id, null, ['enrollment_id' => $enrollmentId, 'net_xaf' => $summary->netXaf]);

        return self::invoice($summary);
    }

    /** @return array{learner_id: string, balance_xaf: int, in_arrears: bool} */
    public function balance(string $tenantId, string $establishmentId, string $learnerId): array
    {
        return [
            'learner_id' => $learnerId,
            'balance_xaf' => $this->debtors->balanceXaf($tenantId, $establishmentId, $learnerId),
            'in_arrears' => in_array($learnerId, $this->debtors->learnerIdsInArrears($tenantId, $establishmentId), true),
        ];
    }

    /** @return array<string, mixed> */
    public static function invoice(InvoiceSummary $summary): array
    {
        $kinds = [];
        foreach ($summary->lines as $line) {
            $kinds[$line['id']] = $line['kind'];
        }
        $dueNow = 0;
        $firstTuition = [];
        foreach ($summary->installments as $installment) {
            $kind = $kinds[$installment['line_id']] ?? 'other';
            if ($kind === 'registration') {
                $dueNow += $installment['amount_xaf'];
            } elseif ($kind === 'tuition') {
                $current = $firstTuition[$installment['line_id']] ?? null;
                if ($current === null || $installment['due_on'] < $current['due_on']) {
                    $firstTuition[$installment['line_id']] = $installment;
                }
            }
        }
        foreach ($firstTuition as $installment) {
            $dueNow += $installment['amount_xaf'];
        }

        return [
            'id' => $summary->id, 'enrollment_id' => $summary->enrollmentId, 'status' => $summary->status, 'gross_xaf' => $summary->grossXaf, 'discount_xaf' => $summary->discountXaf,
            'net_xaf' => $summary->netXaf, 'paid_xaf' => $summary->paidXaf, 'balance_xaf' => $summary->balanceXaf, 'due_at_enrolment_xaf' => $dueNow,
            'lines' => $summary->lines, 'installments' => $summary->installments,
        ];
    }
}
