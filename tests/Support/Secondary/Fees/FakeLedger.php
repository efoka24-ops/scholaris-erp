<?php

declare(strict_types=1);

namespace Tests\Support\Secondary\Fees;

use Scholaris\Modules\Socle\Billing\Contracts\ChargeInvoice;
use Scholaris\Modules\Socle\Billing\Contracts\InvoiceIssuer;
use Scholaris\Modules\Socle\Billing\Contracts\InvoicePayer;
use Scholaris\Modules\Socle\Billing\Contracts\InvoiceSummary;
use Scholaris\Modules\Socle\Billing\Contracts\InvoiceTotals;
use Scholaris\Modules\Socle\Billing\Contracts\LearnerInvoices;

/**
 * Doublure des factures (InvoiceIssuer::find, LearnerInvoices, InvoiceTotals) pour les restes Fees
 * du secondaire. Données fictives ; une facture n'existe que pour son client et son établissement.
 */
final class FakeLedger implements InvoiceIssuer, LearnerInvoices, InvoiceTotals
{
    /** @var array<string, array{tenant: string, summary: InvoiceSummary}> */
    private array $invoices = [];

    /** @param list<array{due_on: string, amount_xaf: int, paid_xaf: int, label?: string}> $installments */
    public function invoice(string $tenantId, string $establishmentId, string $id, string $learnerId, array $installments): void
    {
        $rows = [];
        $net = 0;
        $paid = 0;
        foreach ($installments as $index => $installment) {
            $rows[] = ['id' => $id . '-t' . $index, 'line_id' => $id . '-l', 'label' => $installment['label'] ?? 'Pension tranche ' . ($index + 1), 'due_on' => $installment['due_on'], 'amount_xaf' => $installment['amount_xaf'], 'paid_xaf' => $installment['paid_xaf']];
            $net += $installment['amount_xaf'];
            $paid += $installment['paid_xaf'];
        }
        $this->invoices[$id] = ['tenant' => $tenantId, 'summary' => new InvoiceSummary($id, $establishmentId, $learnerId, InvoiceSummary::KIND_ENROLLMENT, null, null, 'issued', $net, 0, $net, $paid, $net - $paid, null, [], $rows)];
    }

    public function forEnrollment(string $tenantId, string $establishmentId, string $actorId, string $enrollmentId, ?string $level = null, ?string $track = null, array $discounts = [], ?InvoicePayer $payer = null): InvoiceSummary
    {
        throw new \LogicException('Non utilisé.');
    }

    public function forCharges(ChargeInvoice $invoice): InvoiceSummary
    {
        throw new \LogicException('Non utilisé.');
    }

    public function addDiscount(string $tenantId, string $establishmentId, string $actorId, string $invoiceId, string $kind, string $mode, int $value, string $appliesTo, string $reason): InvoiceSummary
    {
        throw new \LogicException('Non utilisé.');
    }

    public function find(string $tenantId, string $establishmentId, string $invoiceId): ?InvoiceSummary
    {
        $entry = $this->invoices[$invoiceId] ?? null;

        return $entry !== null && $entry['tenant'] === $tenantId && $entry['summary']->establishmentId === $establishmentId ? $entry['summary'] : null;
    }

    public function findByReference(string $tenantId, string $establishmentId, string $reference): ?InvoiceSummary
    {
        return null;
    }

    public function idsFor(string $tenantId, string $establishmentId, string $learnerId): array
    {
        $ids = [];
        foreach ($this->invoices as $id => $entry) {
            if ($entry['tenant'] === $tenantId && $entry['summary']->establishmentId === $establishmentId && $entry['summary']->subjectId === $learnerId) {
                $ids[] = (string) $id;
            }
        }

        return $ids;
    }

    public function totals(string $tenantId, string $establishmentId, array $invoiceIds, string $today): array
    {
        $out = [];
        foreach ($invoiceIds as $id) {
            $summary = $this->find($tenantId, $establishmentId, $id);
            if ($summary === null) {
                continue;
            }
            $overdue = 0;
            foreach ($summary->installments as $installment) {
                if ($installment['due_on'] < $today) {
                    $overdue += max(0, $installment['amount_xaf'] - $installment['paid_xaf']);
                }
            }
            $out[$id] = ['net_xaf' => $summary->netXaf, 'paid_xaf' => $summary->paidXaf, 'balance_xaf' => $summary->balanceXaf, 'overdue_xaf' => $overdue];
        }

        return $out;
    }
}
