<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Audit\Contracts\Approvals;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Billing\Infrastructure\Db;
use Scholaris\Modules\Socle\Billing\Infrastructure\PaymentRepository;

/**
 * Annulation d'un paiement : écriture inverse motivée, validée par une AUTRE personne (module Audit, Approvals).
 * Tant que la validation manque, le paiement garde ses effets ; le reçu n'est jamais supprimé, il est marqué annulé.
 */
final class ReversalService
{
    public function __construct(
        private readonly Db $db,
        private readonly PaymentRepository $payments,
        private readonly AllocationService $allocation,
        private readonly Approvals $approvals,
        private readonly AuditLog $audit,
        private readonly BillingClock $clock,
    ) {
    }

    /** @return array<string, mixed> */
    public function request(string $tenantId, string $establishmentId, string $actorId, string $paymentId, string $reason): array
    {
        $payment = $this->payments->payment($tenantId, $paymentId);
        if ($payment === null || $payment['establishment_id'] !== $establishmentId) {
            throw HttpException::notFound('Paiement introuvable.');
        }
        if ($payment['status'] !== 'confirmed') {
            throw HttpException::conflict('Seul un paiement confirmé peut être annulé.');
        }
        if ($this->payments->pendingReversalOf($tenantId, $paymentId) !== null) {
            throw HttpException::conflict('Une annulation est déjà en attente de validation pour ce paiement.');
        }

        return $this->db->transaction(function () use ($tenantId, $establishmentId, $actorId, $payment, $paymentId, $reason): array {
            $approvalId = $this->approvals->request($tenantId, 'payment.reversal', 'payment', $paymentId, $reason, $actorId);
            $id = (string) new Ulid();
            $this->payments->insert('reversals', [
                'id' => $id, 'tenant_id' => $tenantId, 'establishment_id' => $establishmentId, 'payment_id' => $paymentId,
                'amount_xaf' => -(int) $payment['amount_xaf'], 'reason' => $reason, 'status' => 'pending', 'approval_id' => $approvalId,
                'requested_by' => $actorId, 'approved_by' => null, 'created_at' => $this->clock->now(), 'decided_at' => null,
            ]);
            $this->audit->append($tenantId, $actorId, 'payment.reversal.requested', 'payment', $paymentId, null, ['reversal_id' => $id, 'reason' => $reason]);

            return $this->payments->reversal($tenantId, $id) ?? [];
        });
    }

    /** @return array<string, mixed> */
    public function approve(string $tenantId, string $establishmentId, string $approverId, string $reversalId): array
    {
        $reversal = $this->pending($tenantId, $establishmentId, $reversalId);
        if ($reversal['requested_by'] === $approverId) {
            throw HttpException::forbidden('La validation doit être faite par une autre personne que le demandeur.');
        }

        return $this->db->transaction(function () use ($tenantId, $approverId, $reversal, $reversalId): array {
            $this->approvals->approve($tenantId, (string) $reversal['approval_id'], $approverId);
            $paymentId = (string) $reversal['payment_id'];
            $now = $this->clock->now();

            $this->allocation->revert($tenantId, $paymentId);
            $this->payments->updatePayment($tenantId, $paymentId, ['status' => 'reversed', 'credit_xaf' => 0]);
            $this->db->exec("UPDATE receipts SET status = 'cancelled' WHERE tenant_id = ? AND payment_id = ?", [$tenantId, $paymentId]);
            $this->payments->updateReversal($tenantId, $reversalId, ['status' => 'approved', 'approved_by' => $approverId, 'decided_at' => $now]);
            $this->audit->append($tenantId, $approverId, 'payment.reversed', 'payment', $paymentId, ['status' => 'confirmed'], [
                'status' => 'reversed', 'reversal_id' => $reversalId, 'requested_by' => $reversal['requested_by'],
            ]);

            return $this->payments->reversal($tenantId, $reversalId) ?? [];
        });
    }

    /** @return array<string, mixed> */
    public function reject(string $tenantId, string $establishmentId, string $approverId, string $reversalId): array
    {
        $reversal = $this->pending($tenantId, $establishmentId, $reversalId);

        return $this->db->transaction(function () use ($tenantId, $approverId, $reversal, $reversalId): array {
            $this->approvals->reject($tenantId, (string) $reversal['approval_id'], $approverId);
            $this->payments->updateReversal($tenantId, $reversalId, ['status' => 'rejected', 'approved_by' => $approverId, 'decided_at' => $this->clock->now()]);
            $this->audit->append($tenantId, $approverId, 'payment.reversal.rejected', 'payment', (string) $reversal['payment_id'], null, ['reversal_id' => $reversalId]);

            return $this->payments->reversal($tenantId, $reversalId) ?? [];
        });
    }

    /** @return array<string, mixed> */
    private function pending(string $tenantId, string $establishmentId, string $reversalId): array
    {
        $reversal = $this->payments->reversal($tenantId, $reversalId);
        if ($reversal === null || $reversal['establishment_id'] !== $establishmentId) {
            throw HttpException::notFound('Annulation introuvable.');
        }
        if ($reversal['status'] !== 'pending') {
            throw HttpException::conflict('Cette annulation a déjà été traitée.');
        }

        return $reversal;
    }
}
