<?php

declare(strict_types=1);

namespace Tests\Support\Primary;

use Scholaris\Modules\Socle\Billing\Contracts\ChargeInvoice;
use Scholaris\Modules\Socle\Billing\Contracts\InvoiceIssuer;
use Scholaris\Modules\Socle\Billing\Contracts\InvoicePayer;
use Scholaris\Modules\Socle\Billing\Contracts\InvoiceSummary;

/** Doublure d'InvoiceIssuer : enregistre les factures libres (idempotentes par référence), montant fictif. */
final class RecordingInvoices implements InvoiceIssuer
{
    /** @var array<string, ChargeInvoice> */
    public array $charges = [];

    public function forEnrollment(string $tenantId, string $establishmentId, string $actorId, string $enrollmentId, ?string $level = null, ?string $track = null, array $discounts = [], ?InvoicePayer $payer = null): InvoiceSummary
    {
        throw new \LogicException('Non utilisé.');
    }

    public function forCharges(ChargeInvoice $invoice): InvoiceSummary
    {
        $this->charges[$invoice->reference] ??= $invoice;

        return $this->summary($invoice);
    }

    public function addDiscount(string $tenantId, string $establishmentId, string $actorId, string $invoiceId, string $kind, string $mode, int $value, string $appliesTo, string $reason): InvoiceSummary
    {
        throw new \LogicException('Non utilisé.');
    }

    public function find(string $tenantId, string $establishmentId, string $invoiceId): ?InvoiceSummary
    {
        return null;
    }

    public function findByReference(string $tenantId, string $establishmentId, string $reference): ?InvoiceSummary
    {
        return isset($this->charges[$reference]) ? $this->summary($this->charges[$reference]) : null;
    }

    private function summary(ChargeInvoice $invoice): InvoiceSummary
    {
        return new InvoiceSummary('inv-' . md5($invoice->reference), $invoice->establishmentId, $invoice->subjectId, InvoiceSummary::KIND_CHARGES, null, $invoice->reference, 'issued', 5000, 0, 5000, 0, 5000, null, [], []);
    }
}
