<?php

declare(strict_types=1);

namespace Tests\Support\Higher\Admissions;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Billing\Contracts\ChargeInvoice;
use Scholaris\Modules\Socle\Billing\Contracts\InvoiceIssuer;
use Scholaris\Modules\Socle\Billing\Contracts\InvoicePayer;
use Scholaris\Modules\Socle\Billing\Contracts\InvoiceSummary;
use Scholaris\Modules\Socle\Billing\Contracts\PaymentOrder;
use Scholaris\Modules\Socle\Billing\Contracts\PaymentOutcome;
use Scholaris\Modules\Socle\Billing\Contracts\PaymentRequester;

/**
 * Doublure des contrats D4 (factures et paiements) : factures libres en mémoire, paiement au guichet confirmé tout de suite,
 * mobile money en attente jusqu'à confirm(). Les paiements soldent les lignes dans l'ordre.
 */
final class FakeBilling implements InvoiceIssuer, PaymentRequester
{
    /** @var array<string, array{tenant: string, establishment: string, subject: string, reference: ?string, lines: list<array{id: string, fee_item_id: string, fee_code: string|null, kind: string, label: string, gross_xaf: int, discount_xaf: int, net_xaf: int, paid_xaf: int}>}> */
    public array $invoices = [];

    /** @var array<string, array{order: PaymentOrder, status: string, receipt: ?string}> */
    public array $payments = [];

    /** @var list<ChargeInvoice> */
    public array $charges = [];

    public function forEnrollment(string $tenantId, string $establishmentId, string $actorId, string $enrollmentId, ?string $level = null, ?string $track = null, array $discounts = [], ?InvoicePayer $payer = null): InvoiceSummary
    {
        throw new \LogicException('Non utilisé par Admissions et Enrolment.');
    }

    public function forCharges(ChargeInvoice $invoice): InvoiceSummary
    {
        $this->charges[] = $invoice;
        $existing = $this->findByReference($invoice->tenantId, $invoice->establishmentId, $invoice->reference);
        if ($existing !== null) {
            return $existing;
        }
        $lines = [];
        foreach ($invoice->lines as $line) {
            $lines[] = [(string) $line->feeCode, (int) $line->amountXaf, 0];
        }

        return $this->create($invoice->tenantId, $invoice->establishmentId, $invoice->subjectId, $invoice->reference, $lines);
    }

    /** @param list<array{0: string, 1: int, 2: int}> $lines code de frais, montant, déjà payé */
    public function create(string $tenantId, string $establishmentId, string $subjectId, ?string $reference, array $lines): InvoiceSummary
    {
        $id = 'inv-' . (count($this->invoices) + 1);
        $rows = [];
        foreach ($lines as $i => [$code, $amount, $paid]) {
            $rows[] = [
                'id' => $id . '-l' . $i, 'fee_item_id' => 'fee-' . $code, 'fee_code' => $code, 'kind' => 'other', 'label' => $code,
                'gross_xaf' => $amount, 'discount_xaf' => 0, 'net_xaf' => $amount, 'paid_xaf' => $paid,
            ];
        }
        $this->invoices[$id] = ['tenant' => $tenantId, 'establishment' => $establishmentId, 'subject' => $subjectId, 'reference' => $reference, 'lines' => $rows];

        return $this->summary($id);
    }

    public function addDiscount(string $tenantId, string $establishmentId, string $actorId, string $invoiceId, string $kind, string $mode, int $value, string $appliesTo, string $reason): InvoiceSummary
    {
        throw new \LogicException('Non utilisé.');
    }

    public function find(string $tenantId, string $establishmentId, string $invoiceId): ?InvoiceSummary
    {
        $invoice = $this->invoices[$invoiceId] ?? null;

        return $invoice === null || $invoice['tenant'] !== $tenantId || $invoice['establishment'] !== $establishmentId ? null : $this->summary($invoiceId);
    }

    public function findByReference(string $tenantId, string $establishmentId, string $reference): ?InvoiceSummary
    {
        foreach ($this->invoices as $id => $invoice) {
            if ($invoice['tenant'] === $tenantId && $invoice['establishment'] === $establishmentId && $invoice['reference'] === $reference) {
                return $this->summary($id);
            }
        }

        return null;
    }

    public function request(PaymentOrder $order): PaymentOutcome
    {
        if ($this->find($order->tenantId, $order->establishmentId, $order->invoiceId) === null) {
            throw HttpException::notFound('Facture inconnue.');
        }
        foreach ($this->payments as $id => $payment) {
            if ($payment['order']->idempotencyKey === $order->idempotencyKey) {
                return $this->outcome($id, true);
            }
        }
        $id = 'pay-' . (count($this->payments) + 1);
        $this->payments[$id] = ['order' => $order, 'status' => PaymentOutcome::PENDING, 'receipt' => null];
        if ($order->channel === 'cash' || $order->externalReference !== null) {
            $this->confirm($id);
        }

        return $this->outcome($id);
    }

    public function status(string $tenantId, string $establishmentId, string $paymentId): ?PaymentOutcome
    {
        return isset($this->payments[$paymentId]) ? $this->outcome($paymentId) : null;
    }

    /** Confirmation de l'opérateur de mobile money. */
    public function confirm(string $paymentId): void
    {
        $payment = $this->payments[$paymentId];
        if ($payment['status'] === PaymentOutcome::CONFIRMED) {
            return;
        }
        $left = $payment['order']->amountXaf;
        foreach ($this->invoices[$payment['order']->invoiceId]['lines'] as &$line) {
            $take = min($left, $line['net_xaf'] - $line['paid_xaf']);
            $line['paid_xaf'] += $take;
            $left -= $take;
        }
        unset($line);
        $this->payments[$paymentId]['status'] = PaymentOutcome::CONFIRMED;
        $this->payments[$paymentId]['receipt'] = 'R-TEST-' . substr($paymentId, 4);
    }

    private function outcome(string $id, bool $replayed = false): PaymentOutcome
    {
        $payment = $this->payments[$id];

        return new PaymentOutcome($id, $payment['status'], $payment['order']->amountXaf, $payment['order']->channel, $payment['order']->invoiceId, $payment['receipt'] === null ? null : 'rcpt-' . $id, $payment['receipt'], $payment['order']->externalReference, $replayed);
    }

    private function summary(string $id): InvoiceSummary
    {
        $invoice = $this->invoices[$id];
        $net = array_sum(array_column($invoice['lines'], 'net_xaf'));
        $paid = array_sum(array_column($invoice['lines'], 'paid_xaf'));

        return new InvoiceSummary($id, $invoice['establishment'], $invoice['subject'], InvoiceSummary::KIND_CHARGES, null, $invoice['reference'], $paid >= $net ? 'paid' : 'open', $net, 0, $net, $paid, $net - $paid, null, $invoice['lines'], []);
    }
}
