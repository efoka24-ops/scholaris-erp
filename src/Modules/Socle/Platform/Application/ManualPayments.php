<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Platform\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Numbering\SequenceService;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;
use Scholaris\Modules\Socle\Platform\Domain\Subscription;
use Scholaris\Modules\Socle\Platform\Infrastructure\PlatformDb;

/**
 * Paiements enregistrés à la main par l'administrateur de plateforme (espèces, virement) : confirmés immédiatement,
 * avec reçu, prolongation de la période couverte et réactivation comme un paiement mobile money. Annulation motivée :
 * un paiement confirmé n'est annulable que s'il est le dernier de l'application (la couverture revient à l'état antérieur).
 */
final class ManualPayments
{
    public const CHANNELS = ['cash', 'bank_transfer'];
    public const CANCELLED = 'cancelled';

    public function __construct(
        private readonly PlatformDb $db,
        private readonly EntitlementService $entitlements,
        private readonly SubscriptionPayments $payments,
        private readonly SequenceService $sequences,
        private readonly PlatformAudit $audit,
        private readonly PlatformClock $clock,
    ) {
    }

    /**
     * @param int|null $amountXaf montant encaissé (par défaut : tarif effectif × mois)
     * @return array<string, mixed>
     */
    public function record(string $tenantId, string $appKey, int $months, string $channel, ?int $amountXaf, ?string $note, string $actorId, ?string $ip): array
    {
        $entitlement = $this->entitlements->rowOrFail($tenantId, $appKey);
        if ($entitlement['state'] === ApplicationAccess::CLOSED) {
            throw HttpException::conflict('Cette application est fermée : rouvrez-la avant d\'enregistrer un paiement.');
        }
        $period = $entitlement['billing_period_months'] ?? null;
        if ($period !== null && $months % (int) $period !== 0) {
            throw HttpException::validation(['months' => [sprintf('La périodicité de facturation de ce client est de %d mois : choisissez un multiple.', (int) $period)]]);
        }
        $wasSuspended = $entitlement['state'] === ApplicationAccess::SUSPENDED;
        $amount = $amountXaf ?? $this->entitlements->totalOf($entitlement) * $months;
        if ($amount <= 0) {
            throw HttpException::validation(['amount_xaf' => ['Montant obligatoire : aucun tarif n\'est défini pour cette application.']]);
        }

        $id = (string) new Ulid();
        $now = $this->clock->iso();
        $dueOn = Subscription::dueOn($entitlement['paid_until'] === null ? null : (string) $entitlement['paid_until'], (string) $entitlement['trial_ends_on']);
        $coverage = Subscription::coverage($this->clock->today(), $dueOn, $months);
        $receipt = $this->db->transaction(function () use ($id, $tenantId, $entitlement, $appKey, $amount, $months, $channel, $coverage, $actorId, $note, $now): string {
            $year = $this->clock->year();
            $receipt = SequenceService::format('SCH', $year, $this->sequences->next(PlatformAudit::PLATFORM_TENANT, PlatformAudit::PLATFORM_TENANT, 'platform_receipt', $year));
            $this->db->insert('platform_payments', [
                'id' => $id, 'tenant_id' => $tenantId, 'tenant_application_id' => (string) $entitlement['id'], 'app_key' => $appKey,
                'reference' => 'MAN-' . $id, 'provider_reference' => null, 'idempotency_key' => null, 'amount_xaf' => $amount, 'months' => $months,
                'channel' => $channel, 'payer_phone' => '', 'status' => SubscriptionPayments::SUCCEEDED, 'receipt_number' => $receipt,
                'covered_from' => $coverage['from'], 'covered_until' => $coverage['until'], 'initiated_by' => $actorId, 'paid_at' => $now,
                'payment_note' => $note, 'created_at' => $now, 'updated_at' => $now,
            ]);
            // L'application n'est pas fermée (vérifié plus haut) : le paiement la rend active.
            $this->db->update('tenant_applications', [
                'paid_until' => $coverage['until'], 'state' => ApplicationAccess::ACTIVE, 'past_due_since' => null, 'suspended_at' => null,
                'state_reason' => null, 'updated_at' => $now,
            ] + EntitlementService::LOCK_CLEARED, ['tenant_id' => $tenantId, 'id' => (string) $entitlement['id']]);

            return $receipt;
        });
        $payment = $this->payments->findOrFail($tenantId, $id);
        $this->audit->record($tenantId, $actorId, 'platform.payment_recorded', 'platform_payment', $id, [
            'app_key' => $appKey, 'amount_xaf' => $amount, 'months' => $months, 'channel' => $channel, 'receipt_number' => $receipt,
        ], $ip);
        $this->payments->sendReceipt($payment);
        if ($wasSuspended) {
            $this->payments->sendLifted($payment);
        }

        return $this->payments->present($payment);
    }

    /** @return array<string, mixed> */
    public function cancel(string $paymentId, string $reason, string $actorId, ?string $ip): array
    {
        $payment = $this->db->one('SELECT * FROM platform_payments WHERE id = ?', [$paymentId]) ?? throw HttpException::notFound('Paiement introuvable.');
        $tenantId = (string) $payment['tenant_id'];
        $status = (string) $payment['status'];
        if (!in_array($status, [SubscriptionPayments::PENDING, SubscriptionPayments::SUCCEEDED], true)) {
            throw HttpException::conflict('Ce paiement est déjà échoué ou annulé.');
        }
        $now = $this->clock->iso();
        $this->db->transaction(function () use ($payment, $tenantId, $status, $reason, $actorId, $now): void {
            if ($status === SubscriptionPayments::SUCCEEDED) {
                $this->revertCoverage($payment, $now);
            }
            $this->db->update('platform_payments', [
                'status' => self::CANCELLED, 'cancelled_at' => $now, 'cancelled_by' => $actorId, 'cancel_reason' => $reason, 'updated_at' => $now,
            ], ['tenant_id' => $tenantId, 'id' => (string) $payment['id']]);
        });
        $this->audit->record($tenantId, $actorId, 'platform.payment_cancelled', 'platform_payment', $paymentId, [
            'previous_status' => $status, 'amount_xaf' => (int) $payment['amount_xaf'], 'receipt_number' => $payment['receipt_number'], 'reason' => $reason,
        ], $ip);

        return $this->payments->present($this->payments->findOrFail($tenantId, $paymentId));
    }

    /** @param array<string, mixed> $payment */
    private function revertCoverage(array $payment, string $now): void
    {
        $tenantId = (string) $payment['tenant_id'];
        $entitlement = $this->entitlements->rowOrFail($tenantId, (string) $payment['app_key']);
        if ($entitlement['paid_until'] !== $payment['covered_until']) {
            throw HttpException::conflict('Un paiement plus récent couvre cette application : annulez d\'abord le plus récent.');
        }
        $previous = $this->db->scalar(
            'SELECT MAX(covered_until) FROM platform_payments WHERE tenant_id = ? AND tenant_application_id = ? AND status = ? AND id <> ?',
            [$tenantId, (string) $entitlement['id'], SubscriptionPayments::SUCCEEDED, (string) $payment['id']],
        );
        $paidUntil = is_string($previous) && $previous !== '' ? $previous : null;
        $values = ['paid_until' => $paidUntil, 'updated_at' => $now];
        if (in_array($entitlement['state'], [ApplicationAccess::TRIAL, ApplicationAccess::ACTIVE, ApplicationAccess::PAST_DUE], true)) {
            $today = $this->clock->today();
            $dueOn = Subscription::dueOn($paidUntil, (string) $entitlement['trial_ends_on']);
            $values += strcmp($today, $dueOn) <= 0
                ? ['state' => $paidUntil === null ? ApplicationAccess::TRIAL : ApplicationAccess::ACTIVE, 'past_due_since' => null]
                : ['state' => ApplicationAccess::PAST_DUE, 'past_due_since' => $today];
        }
        $this->db->update('tenant_applications', $values, ['tenant_id' => $tenantId, 'id' => (string) $entitlement['id']]);
    }
}
