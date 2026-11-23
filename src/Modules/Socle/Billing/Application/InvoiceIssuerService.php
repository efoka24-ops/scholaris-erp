<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Billing\Contracts\ChargeInvoice;
use Scholaris\Modules\Socle\Billing\Contracts\InvoiceIssuer;
use Scholaris\Modules\Socle\Billing\Contracts\InvoicePayer;
use Scholaris\Modules\Socle\Billing\Contracts\InvoiceSummary;
use Scholaris\Modules\Socle\Billing\Infrastructure\InvoiceRepository;

/** Implémentation du contrat d'écriture des factures (D4) : délègue à InvoiceService, mêmes règles que l'API. */
final class InvoiceIssuerService implements InvoiceIssuer
{
    public function __construct(
        private readonly InvoiceService $service,
        private readonly InvoiceRepository $invoices,
    ) {
    }

    public function forEnrollment(
        string $tenantId,
        string $establishmentId,
        string $actorId,
        string $enrollmentId,
        ?string $level = null,
        ?string $track = null,
        array $discounts = [],
        ?InvoicePayer $payer = null,
    ): InvoiceSummary {
        $detail = $this->service->generate($tenantId, $establishmentId, $actorId, $enrollmentId, $level, $track, $discounts, $payer);

        return $this->summary($tenantId, $detail);
    }

    public function forCharges(ChargeInvoice $invoice): InvoiceSummary
    {
        return $this->summary($invoice->tenantId, $this->service->generateCharges($invoice));
    }

    public function addDiscount(
        string $tenantId,
        string $establishmentId,
        string $actorId,
        string $invoiceId,
        string $kind,
        string $mode,
        int $value,
        string $appliesTo,
        string $reason,
    ): InvoiceSummary {
        $this->service->addDiscount($tenantId, $establishmentId, $actorId, $invoiceId, $kind, $mode, $value, $appliesTo, $reason);

        return $this->find($tenantId, $establishmentId, $invoiceId) ?? throw HttpException::notFound('Facture introuvable.');
    }

    public function find(string $tenantId, string $establishmentId, string $invoiceId): ?InvoiceSummary
    {
        $invoice = $this->invoices->find($tenantId, $invoiceId);
        if ($invoice === null || $invoice['establishment_id'] !== $establishmentId) {
            return null;
        }

        return $this->summary($tenantId, $this->service->detail($tenantId, $invoiceId));
    }

    public function findByReference(string $tenantId, string $establishmentId, string $reference): ?InvoiceSummary
    {
        $invoice = $this->invoices->findForEnrollment($tenantId, InvoiceService::chargeKey($reference));

        return $invoice === null ? null : $this->find($tenantId, $establishmentId, (string) $invoice['id']);
    }

    /** @param array<string, mixed> $detail */
    private function summary(string $tenantId, array $detail): InvoiceSummary
    {
        $id = (string) $detail['id'];
        $paid = $this->invoices->paidByLine($tenantId, $id);
        $lines = [];
        foreach ((array) ($detail['lines'] ?? []) as $line) {
            $lines[] = [
                'id' => (string) $line['id'],
                'fee_item_id' => (string) $line['fee_item_id'],
                'fee_code' => isset($line['fee_code']) ? (string) $line['fee_code'] : null,
                'kind' => (string) $line['kind'],
                'label' => (string) $line['label'],
                'gross_xaf' => (int) $line['gross_xaf'],
                'discount_xaf' => (int) $line['discount_xaf'],
                'net_xaf' => (int) $line['net_xaf'],
                'paid_xaf' => $paid[(string) $line['id']] ?? 0,
            ];
        }
        $installments = [];
        foreach ((array) ($detail['installments'] ?? []) as $row) {
            $installments[] = [
                'id' => (string) $row['id'],
                'line_id' => (string) $row['line_id'],
                'label' => (string) $row['label'],
                'due_on' => (string) $row['due_on'],
                'amount_xaf' => (int) $row['amount_xaf'],
                'paid_xaf' => (int) $row['paid_xaf'],
            ];
        }
        $key = (string) $detail['enrollment_id'];
        $charges = ($detail['invoice_kind'] ?? null) === InvoiceSummary::KIND_CHARGES;
        $payer = ($detail['payer_name'] ?? null) !== null
            ? new InvoicePayer((string) $detail['payer_kind'], (string) $detail['payer_name'], isset($detail['payer_ref']) ? (string) $detail['payer_ref'] : null)
            : null;

        return new InvoiceSummary(
            $id,
            (string) $detail['establishment_id'],
            (string) $detail['learner_id'],
            $charges ? InvoiceSummary::KIND_CHARGES : InvoiceSummary::KIND_ENROLLMENT,
            $charges ? null : $key,
            $charges && str_starts_with($key, 'ref:') ? substr($key, 4) : null,
            (string) $detail['status'],
            (int) $detail['gross_xaf'],
            (int) $detail['discount_xaf'],
            (int) $detail['net_xaf'],
            (int) $detail['paid_xaf'],
            (int) $detail['balance_xaf'],
            $payer,
            $lines,
            $installments,
        );
    }
}
