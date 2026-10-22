<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Billing\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Primary\Support\Access;
use Scholaris\Modules\Socle\Billing\Contracts\InvoiceIssuer;
use Scholaris\Modules\Socle\Billing\Contracts\InvoiceSummary;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;

/**
 * Facture d'une inscription du primaire (EX-PRI-34, parcours 3) : frais d'inscription + pension par tranches selon les grilles de
 * l'école, remise de fratrie appliquée par le socle (paramètres billing.*), jamais de montant codé ici. Rend aussi ce qui est à
 * payer à l'inscription : frais d'inscription et PREMIÈRE tranche de chaque pension.
 */
final class EnrolmentInvoicing
{
    public const PERMISSIONS = ['primary.enrol'];

    public function __construct(
        private readonly InvoiceIssuer $invoices,
        private readonly EnrollmentDirectory $enrollments,
        private readonly Access $access,
    ) {
    }

    /** @return array<string, mixed> */
    public function issue(Principal $principal, string $establishmentId, string $enrollmentId): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, self::PERMISSIONS);
        $enrollment = $this->enrollments->find($principal->tenantId, $enrollmentId);
        if ($enrollment === null || $enrollment->establishmentId !== $establishmentId) {
            throw HttpException::notFound('Inscription introuvable dans cet établissement.');
        }
        $this->access->assertWritableClass($principal, $perimeter, $enrollment->structureId, 'enrollment', $enrollmentId);
        $summary = $this->invoices->forEnrollment($principal->tenantId, $establishmentId, $principal->userId, $enrollmentId);
        $this->access->audit($principal, 'primary.enrolment_invoice.issued', 'invoice', $summary->id, null, ['enrollment_id' => $enrollmentId, 'net_xaf' => $summary->netXaf]);

        return self::resource($summary);
    }

    /** @return array<string, mixed> */
    public static function resource(InvoiceSummary $summary): array
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
            'id' => $summary->id, 'enrollment_id' => $summary->enrollmentId, 'status' => $summary->status, 'gross_xaf' => $summary->grossXaf,
            'discount_xaf' => $summary->discountXaf, 'net_xaf' => $summary->netXaf, 'paid_xaf' => $summary->paidXaf, 'balance_xaf' => $summary->balanceXaf,
            'due_at_enrolment_xaf' => $dueNow, 'lines' => $summary->lines, 'installments' => $summary->installments,
        ];
    }
}
