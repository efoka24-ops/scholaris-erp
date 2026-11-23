<?php

declare(strict_types=1);

namespace Tests\Support\Nursery;

use Scholaris\Modules\Socle\Billing\Contracts\ChargeInvoice;
use Scholaris\Modules\Socle\Billing\Contracts\DebtorDirectory;
use Scholaris\Modules\Socle\Billing\Contracts\InvoiceIssuer;
use Scholaris\Modules\Socle\Billing\Contracts\InvoicePayer;
use Scholaris\Modules\Socle\Billing\Contracts\InvoiceSummary;

/** Doublure de Billing : une facture d'inscription (inscription + 3 tranches de pension) et des soldes pilotés par le test. */
final class WorldBilling implements InvoiceIssuer, DebtorDirectory
{
    /** @var array<string, int> */
    public array $balances = [];
    /** @var list<string> */
    public array $invoiced = [];

    public function forEnrollment(string $tenantId, string $establishmentId, string $actorId, string $enrollmentId, ?string $level = null, ?string $track = null, array $discounts = [], ?InvoicePayer $payer = null): InvoiceSummary
    {
        $this->invoiced[] = $enrollmentId;
        $lines = [
            ['id' => 'L1', 'fee_item_id' => 'F1', 'fee_code' => 'REG', 'kind' => 'registration', 'label' => 'Inscription (exemple)', 'gross_xaf' => 10000, 'discount_xaf' => 0, 'net_xaf' => 10000, 'paid_xaf' => 0],
            ['id' => 'L2', 'fee_item_id' => 'F2', 'fee_code' => 'PEN', 'kind' => 'tuition', 'label' => 'Pension (exemple)', 'gross_xaf' => 30000, 'discount_xaf' => 3000, 'net_xaf' => 27000, 'paid_xaf' => 0],
        ];
        $installments = [
            ['id' => 'I1', 'line_id' => 'L1', 'label' => 'Inscription', 'due_on' => '2026-10-21', 'amount_xaf' => 10000, 'paid_xaf' => 0],
            ['id' => 'I2', 'line_id' => 'L2', 'label' => 'Tranche 1', 'due_on' => '2026-10-21', 'amount_xaf' => 9000, 'paid_xaf' => 0],
            ['id' => 'I3', 'line_id' => 'L2', 'label' => 'Tranche 2', 'due_on' => '2026-12-01', 'amount_xaf' => 9000, 'paid_xaf' => 0],
            ['id' => 'I4', 'line_id' => 'L2', 'label' => 'Tranche 3', 'due_on' => '2027-02-01', 'amount_xaf' => 9000, 'paid_xaf' => 0],
        ];

        return new InvoiceSummary('INV1', $establishmentId, 'c1', InvoiceSummary::KIND_ENROLLMENT, $enrollmentId, null, 'issued', 40000, 3000, 37000, 0, 37000, null, $lines, $installments);
    }

    /** @var array<string, ChargeInvoice> factures libres par référence (services) */
    public array $charges = [];
    /** @var array<string, int> montant fictif par frais du catalogue */
    public array $feeAmounts = ['FEE-CANTINE' => 15000, 'FEE-GARDERIE' => 10000];

    public function forCharges(ChargeInvoice $invoice): InvoiceSummary
    {
        $line = $invoice->lines[0];
        $amount = $this->feeAmounts[(string) $line->feeItemId] ?? throw new \Scholaris\Core\Http\HttpException(404, 'Not Found', 'Frais introuvable.');
        $this->charges[$invoice->reference] = $invoice;
        $id = 'CHG-' . substr(md5($invoice->reference), 0, 8);
        $lines = [['id' => 'CL1', 'fee_item_id' => (string) $line->feeItemId, 'fee_code' => null, 'kind' => 'canteen', 'label' => (string) $line->label, 'gross_xaf' => $amount, 'discount_xaf' => 0, 'net_xaf' => $amount, 'paid_xaf' => 0]];
        $installments = [['id' => 'CI1', 'line_id' => 'CL1', 'label' => (string) $line->label, 'due_on' => $line->dueOn, 'amount_xaf' => $amount, 'paid_xaf' => 0]];

        return new InvoiceSummary($id, $invoice->establishmentId, $invoice->subjectId, InvoiceSummary::KIND_CHARGES, null, $invoice->reference, 'issued', $amount, 0, $amount, 0, $amount, null, $lines, $installments);
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
        return null;
    }

    public function learnerIdsInArrears(string $tenantId, string $establishmentId): array
    {
        return array_keys(array_filter($this->balances, static fn (int $b): bool => $b > 0));
    }

    public function balanceXaf(string $tenantId, string $establishmentId, string $learnerId): int
    {
        return $this->balances[$learnerId] ?? 0;
    }

    public function isBlocked(string $tenantId, string $establishmentId, string $learnerId, string $scope): bool
    {
        return false;
    }
}
