<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Application;

use PDOException;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Billing\Domain\PaymentChannel;
use Scholaris\Modules\Socle\Billing\Domain\PaymentStatus;
use Scholaris\Modules\Socle\Billing\Infrastructure\CashRepository;
use Scholaris\Modules\Socle\Billing\Infrastructure\Db;
use Scholaris\Modules\Socle\Billing\Infrastructure\InvoiceRepository;
use Scholaris\Modules\Socle\Billing\Infrastructure\PaymentRepository;
use Scholaris\Modules\Socle\Integrations\Contracts\PaymentGateway;

/**
 * Enregistrement d'un paiement : une seule transaction pour le paiement, ses affectations, son reçu numéroté
 * et l'audit. Idempotent : la même clé d'idempotence renvoie le même résultat, sans second crédit.
 */
final class PaymentService
{
    public function __construct(
        private readonly Db $db,
        private readonly PaymentRepository $payments,
        private readonly InvoiceRepository $invoices,
        private readonly CashRepository $cash,
        private readonly AllocationService $allocation,
        private readonly ReceiptService $receipts,
        private readonly BillingClock $clock,
        private readonly AuditLog $audit,
        private readonly PaymentGateway $gateway,
    ) {
    }

    public function record(RecordPayment $cmd): PaymentResult
    {
        if ($cmd->amountXaf <= 0) {
            throw HttpException::validation(['amount_xaf' => ['Le montant doit être un entier positif en XAF.']]);
        }
        $existing = $this->payments->paymentByKey($cmd->tenantId, $cmd->idempotencyKey);
        if ($existing !== null) {
            return $this->replay($cmd, $existing);
        }

        $reference = $cmd->familyReference === null ? null : ($this->payments->referenceForTenant($cmd->tenantId, $cmd->familyReference)
            ?? throw HttpException::notFound('Référence de paiement inconnue.'));
        $invoice = $cmd->invoiceId === null ? null : $this->invoices->find($cmd->tenantId, $cmd->invoiceId);
        if ($cmd->invoiceId !== null && ($invoice === null || $invoice['establishment_id'] !== $cmd->establishmentId)) {
            throw HttpException::notFound('Facture introuvable.');
        }
        if ($reference === null && $cmd->learnerId === null && $invoice === null && !($cmd->channel->isMobile() && $cmd->externalReference !== null)) {
            throw HttpException::validation(['family_reference' => ['Indiquez la référence famille, l\'apprenant ou la facture à payer.']]);
        }

        $session = null;
        if ($cmd->channel === PaymentChannel::Cash) {
            $session = $this->cash->openFor($cmd->tenantId, $cmd->establishmentId, (string) $cmd->actorId)
                ?? throw HttpException::conflict('Aucune caisse ouverte : ouvrez la caisse avant d\'encaisser des espèces.');
        }

        $pending = $cmd->channel->isMobile() && $cmd->externalReference === null;
        $externalReference = $cmd->externalReference;
        if ($pending) {
            if ($cmd->payerPhone === null) {
                throw HttpException::validation(['payer_phone' => ['Le numéro à débiter est obligatoire pour un paiement mobile money.']]);
            }
            $externalReference = $this->gateway->initiate($cmd->amountXaf, $cmd->channel->value, $cmd->payerPhone, (string) ($reference['reference'] ?? $cmd->learnerId ?? $cmd->invoiceId))['provider_reference'];
        }

        $id = (string) new Ulid();
        $row = [
            'id' => $id, 'tenant_id' => $cmd->tenantId, 'establishment_id' => $cmd->establishmentId,
            'payer_reference_id' => $reference['id'] ?? null, 'learner_id' => $reference === null ? $cmd->learnerId : null,
            'invoice_id' => $reference === null ? $cmd->invoiceId : null,
            'channel' => $cmd->channel->value, 'amount_xaf' => $cmd->amountXaf, 'credit_xaf' => $cmd->amountXaf,
            'status' => ($pending ? PaymentStatus::Pending : PaymentStatus::Confirmed)->value,
            'external_reference' => $externalReference, 'idempotency_key' => $cmd->idempotencyKey, 'payer_phone' => $cmd->payerPhone,
            'cash_session_id' => $session['id'] ?? null, 'received_by' => $cmd->actorId, 'received_at' => $this->clock->now(),
            'reconciled_at' => $cmd->channel->isMobile() && !$pending && $reference !== null ? $this->clock->now() : null,
            'created_at' => $this->clock->now(),
        ];

        try {
            /** @var array{0: array<string, mixed>, 1: array<string, mixed>|null} $done */
            $done = $this->db->transaction(function () use ($row, $pending, $cmd): array {
                $this->payments->insert('payments', $row);
                if ($pending) {
                    $this->audit->append($cmd->tenantId, $cmd->actorId, 'payment.initiated', 'payment', (string) $row['id'], null, ['amount_xaf' => $cmd->amountXaf, 'channel' => $cmd->channel->value]);

                    return [$row, null];
                }

                return $this->finalize($cmd->tenantId, $row, $cmd->actorId);
            });
        } catch (PDOException $e) {
            if (!$this->db->isUniqueViolation($e)) {
                throw $e;
            }
            $existing = $this->payments->paymentByKey($cmd->tenantId, $cmd->idempotencyKey);
            if ($existing !== null) {
                return $this->replay($cmd, $existing);
            }
            throw HttpException::conflict('Cette référence externe est déjà enregistrée pour un autre paiement.');
        }

        if ($done[1] !== null) {
            $this->receipts->notify($cmd->tenantId, $done[1], $done[0]);
        }

        return new PaymentResult($this->payments->payment($cmd->tenantId, (string) $row['id']) ?? $row, $done[1]);
    }

    /**
     * Confirme un paiement en attente (webhook de l'agrégateur) : affectation, reçu, audit. Sans effet s'il est déjà traité.
     *
     * @param array<string, mixed> $payment
     */
    public function confirmPending(string $tenantId, array $payment): PaymentResult
    {
        /** @var array{0: array<string, mixed>, 1: array<string, mixed>|null} $done */
        $done = $this->db->transaction(function () use ($tenantId, $payment): array {
            $changed = $this->db->exec(
                "UPDATE payments SET status = 'confirmed', reconciled_at = ? WHERE tenant_id = ? AND id = ? AND status = 'pending'",
                [$payment['payer_reference_id'] !== null ? $this->clock->now() : null, $tenantId, (string) $payment['id']],
            );
            $fresh = $this->payments->payment($tenantId, (string) $payment['id']) ?? $payment;
            if ($changed === 0) {
                return [$fresh, $this->payments->receiptForPayment($tenantId, (string) $payment['id'])];
            }

            return $this->finalize($tenantId, $fresh, null);
        });

        if ($done[1] !== null && $done[1]['sms_sent_at'] === null) {
            $this->receipts->notify($tenantId, $done[1], $done[0]);
        }

        return new PaymentResult($this->payments->payment($tenantId, (string) $payment['id']) ?? $done[0], $done[1]);
    }

    public function fail(string $tenantId, string $paymentId): void
    {
        $this->db->exec("UPDATE payments SET status = 'failed' WHERE tenant_id = ? AND id = ? AND status = 'pending'", [$tenantId, $paymentId]);
    }

    /**
     * @param array<string, mixed> $payment
     * @return array{0: array<string, mixed>, 1: array<string, mixed>|null}
     */
    private function finalize(string $tenantId, array $payment, ?string $actorId): array
    {
        $plan = $this->allocation->allocate($tenantId, $payment);
        $receipt = $this->receipts->issue($tenantId, $payment);
        $this->audit->append($tenantId, $actorId, 'payment.recorded', 'payment', (string) $payment['id'], null, [
            'amount_xaf' => (int) $payment['amount_xaf'], 'channel' => $payment['channel'], 'receipt' => $receipt['number'] ?? null,
            'allocated_xaf' => (int) $payment['amount_xaf'] - $plan->surplus, 'credit_xaf' => $plan->surplus, 'rule' => $plan->ruleVersion,
        ]);

        return [$this->payments->payment($tenantId, (string) $payment['id']) ?? $payment, $receipt];
    }

    /**
     * @param array<string, mixed> $existing
     */
    private function replay(RecordPayment $cmd, array $existing): PaymentResult
    {
        if ((int) $existing['amount_xaf'] !== $cmd->amountXaf || $existing['channel'] !== $cmd->channel->value) {
            throw HttpException::validation(['Idempotency-Key' => ['Cette clé a déjà servi pour un autre paiement.']]);
        }

        return new PaymentResult($existing, $this->payments->receiptForPayment($cmd->tenantId, (string) $existing['id']), true);
    }
}
