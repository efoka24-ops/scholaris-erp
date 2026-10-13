<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Application;

use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Billing\Domain\AllocationPlan;
use Scholaris\Modules\Socle\Billing\Domain\AllocationPlanner;
use Scholaris\Modules\Socle\Billing\Infrastructure\Db;
use Scholaris\Modules\Socle\Billing\Infrastructure\InvoiceRepository;
use Scholaris\Modules\Socle\Billing\Infrastructure\PaymentRepository;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/**
 * Affecte un paiement aux tranches des enfants de la famille (tous établissements du client) selon la règle
 * paramétrée ; la version de la règle est enregistrée avec chaque affectation. Le surplus reste en crédit sur le paiement.
 * À appeler dans la transaction du paiement.
 */
final class AllocationService
{
    private readonly AllocationPlanner $planner;

    public function __construct(
        private readonly Db $db,
        private readonly InvoiceRepository $invoices,
        private readonly PaymentRepository $payments,
        private readonly PersonDirectory $people,
        private readonly BillingSettings $settings,
        private readonly BillingClock $clock,
    ) {
        $this->planner = new AllocationPlanner();
    }

    /**
     * Apprenants visés par un paiement : la famille (référence), un apprenant ou une facture.
     *
     * @param array<string, mixed> $payment
     * @return list<string>
     */
    public function learnersOf(string $tenantId, array $payment): array
    {
        if ($payment['payer_reference_id'] !== null) {
            $reference = $this->payments->referenceById($tenantId, (string) $payment['payer_reference_id']);

            return $reference === null ? [] : array_map(
                static fn ($learner): string => $learner->id,
                $this->people->learnersOf($tenantId, (string) $reference['guarantor_id']),
            );
        }
        if ($payment['learner_id'] !== null) {
            return [(string) $payment['learner_id']];
        }
        if ($payment['invoice_id'] !== null) {
            $invoice = $this->invoices->find($tenantId, (string) $payment['invoice_id']);

            return $invoice === null ? [] : [(string) $invoice['learner_id']];
        }

        return [];
    }

    /** @param array<string, mixed> $payment */
    public function allocate(string $tenantId, array $payment): AllocationPlan
    {
        return $this->distribute($tenantId, $payment, (int) $payment['credit_xaf']);
    }

    /** Affecte aux nouvelles sommes dues le crédit conservé sur les paiements d'une famille. */
    public function applyCreditForReference(string $tenantId, string $referenceId): void
    {
        foreach ($this->payments->creditedPayments($tenantId, $referenceId) as $payment) {
            $this->allocate($tenantId, $payment);
        }
    }

    public function applyCreditForLearner(string $tenantId, string $learnerId): void
    {
        foreach ($this->people->guarantorsOf($tenantId, $learnerId) as $guarantor) {
            $reference = $this->payments->referenceOfGuarantor($tenantId, $guarantor->id);
            if ($reference !== null) {
                $this->db->transaction(fn () => $this->applyCreditForReference($tenantId, (string) $reference['id']));
            }
        }
    }

    /** Écriture inverse : des affectations négatives annulent les affectations du paiement ; les tranches redeviennent dues. */
    public function revert(string $tenantId, string $paymentId): void
    {
        $now = $this->clock->now();
        foreach ($this->payments->allocationsOf($tenantId, $paymentId) as $allocation) {
            $amount = (int) $allocation['amount_xaf'];
            if ($amount <= 0) {
                continue;
            }
            $this->payments->insert('allocations', [
                'id' => (string) new Ulid(), 'tenant_id' => $tenantId, 'establishment_id' => (string) $allocation['establishment_id'],
                'payment_id' => $paymentId, 'installment_id' => (string) $allocation['installment_id'], 'learner_id' => (string) $allocation['learner_id'],
                'amount_xaf' => -$amount, 'rule_version' => (string) $allocation['rule_version'], 'created_at' => $now,
            ]);
            $this->invoices->addPaid($tenantId, (string) $allocation['installment_id'], -$amount);
            $installment = $this->invoices->installment($tenantId, (string) $allocation['installment_id']);
            if ($installment !== null) {
                $this->invoices->recompute($tenantId, (string) $installment['invoice_id'], $now);
            }
        }
    }

    /** @param array<string, mixed> $payment */
    private function distribute(string $tenantId, array $payment, int $amount): AllocationPlan
    {
        $rule = $this->settings->allocationRule($tenantId, (string) $payment['establishment_id']);
        $invoiceId = $payment['payer_reference_id'] === null && $payment['invoice_id'] !== null ? (string) $payment['invoice_id'] : null;
        $dues = $this->invoices->dues($tenantId, $this->learnersOf($tenantId, $payment), $invoiceId);
        $plan = $this->planner->plan($amount, $dues, $rule, $this->clock->today());

        $now = $this->clock->now();
        $touched = [];
        foreach ($plan->lines as $line) {
            $due = $line['due'];
            $this->payments->insert('allocations', [
                'id' => (string) new Ulid(), 'tenant_id' => $tenantId, 'establishment_id' => $due->establishmentId,
                'payment_id' => (string) $payment['id'], 'installment_id' => $due->installmentId, 'learner_id' => $due->learnerId,
                'amount_xaf' => $line['amount'], 'rule_version' => $plan->ruleVersion, 'created_at' => $now,
            ]);
            $this->invoices->addPaid($tenantId, $due->installmentId, $line['amount']);
            $touched[$due->invoiceId] = true;
        }
        foreach (array_keys($touched) as $invoiceId) {
            $this->invoices->recompute($tenantId, (string) $invoiceId, $now);
        }
        $this->payments->updatePayment($tenantId, (string) $payment['id'], ['credit_xaf' => $plan->surplus]);

        return $plan;
    }
}
