<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Platform\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Numbering\SequenceService;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;
use Scholaris\Modules\Socle\Platform\Domain\Calendar;
use Scholaris\Modules\Socle\Platform\Domain\Regularisation;
use Scholaris\Modules\Socle\Platform\Domain\Subscription;
use Scholaris\Modules\Socle\Platform\Infrastructure\PlatformDb;

/**
 * Paiements enregistrés à la main par l'administrateur de plateforme (espèces, virement) : confirmés immédiatement,
 * avec reçu, prolongation de la période couverte et réactivation comme un paiement mobile money. Annulation motivée de tout
 * paiement (manuel, passerelle ou partiel d'une déclaration) : voir cancel().
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
        private readonly PlatformNotifier $notifier,
    ) {
    }

    /**
     * @param int|null $amountXaf montant encaissé (par défaut : tarif effectif × mois)
     * @param bool $notify faux : l'appelant envoie lui-même le message au client (déclaration de paiement confirmée)
     * @return array<string, mixed>
     */
    public function record(string $tenantId, string $appKey, int $months, string $channel, ?int $amountXaf, ?string $note, string $actorId, ?string $ip, bool $notify = true): array
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
            ] + EntitlementService::LOCK_CLEARED + EntitlementService::CREDIT_CLEARED, ['tenant_id' => $tenantId, 'id' => (string) $entitlement['id']]);

            return $receipt;
        });
        $payment = $this->payments->findOrFail($tenantId, $id);
        $this->audit->record($tenantId, $actorId, 'platform.payment_recorded', 'platform_payment', $id, [
            'app_key' => $appKey, 'amount_xaf' => $amount, 'months' => $months, 'channel' => $channel, 'receipt_number' => $receipt,
        ], $ip);
        if (!$notify) {
            return $this->payments->present($payment);
        }
        $this->payments->sendReceipt($payment);
        if ($wasSuspended) {
            $this->payments->sendLifted($payment);
        }

        return $this->payments->present($payment);
    }

    /**
     * Annule un paiement (motif obligatoire). Un paiement confirmé n'est annulable que s'il est le dernier de l'application ;
     * la situation est alors recalculée à partir des paiements qui restent : couverture, crédit des paiements partiels du cycle
     * impayé (jamais négatif), état d'après les règles de suspension (re-blocage au niveau du client si le solde n'est plus
     * couvert et le délai de grâce passé). La déclaration de paiement liée passe à `cancelled` avec le motif ; le client est
     * prévenu ; le journal garde l'avant et l'après. Annuler un paiement déjà annulé ne change rien (idempotent).
     *
     * @return array<string, mixed>
     */
    public function cancel(string $paymentId, string $reason, string $actorId, ?string $ip): array
    {
        $payment = $this->db->one('SELECT * FROM platform_payments WHERE id = ?', [$paymentId]) ?? throw HttpException::notFound('Paiement introuvable.');
        $tenantId = (string) $payment['tenant_id'];
        $status = (string) $payment['status'];
        if ($status === self::CANCELLED) {
            return $this->payments->present($payment);
        }
        if (!in_array($status, [SubscriptionPayments::PENDING, SubscriptionPayments::SUCCEEDED], true)) {
            throw HttpException::conflict('Ce paiement est déjà échoué ou annulé.');
        }
        $now = $this->clock->iso();
        $outcome = $this->db->transaction(function () use ($payment, $paymentId, $tenantId, $status, $reason, $actorId, $now): ?array {
            // Condition sur l'ancien statut : de deux annulations simultanées, une seule s'applique.
            $changed = $this->db->update('platform_payments', [
                'status' => self::CANCELLED, 'cancelled_at' => $now, 'cancelled_by' => $actorId, 'cancel_reason' => $reason, 'updated_at' => $now,
            ], ['tenant_id' => $tenantId, 'id' => $paymentId, 'status' => $status]);
            if ($changed === 0) {
                return null;
            }
            $outcome = ['before' => null, 'after' => null, 'relocked' => null, 'balance_xaf' => null, 'declaration' => null];
            if ($status === SubscriptionPayments::SUCCEEDED) {
                $outcome = $this->revertCoverage($payment, $now) + $outcome;
            }
            $declaration = $this->db->one('SELECT id, state FROM platform_regularisation_requests WHERE tenant_id = ? AND payment_id = ?', [$tenantId, $paymentId]);
            if ($declaration !== null && $declaration['state'] !== RegularisationRequests::CANCELLED) {
                $this->db->update('platform_regularisation_requests', [
                    'state' => RegularisationRequests::CANCELLED, 'open_key' => null, 'decision_reason' => 'Paiement annulé : ' . $reason, 'updated_at' => $now,
                ], ['tenant_id' => $tenantId, 'id' => (string) $declaration['id']]);
                $outcome['declaration'] = ['id' => (string) $declaration['id'], 'before' => (string) $declaration['state'], 'after' => RegularisationRequests::CANCELLED];
            }

            return $outcome;
        });
        if (!is_array($outcome)) {
            return $this->payments->present($this->payments->findOrFail($tenantId, $paymentId));
        }
        $this->audit->record($tenantId, $actorId, 'platform.payment_cancelled', 'platform_payment', $paymentId, [
            'previous_status' => $status, 'amount_xaf' => (int) $payment['amount_xaf'], 'receipt_number' => $payment['receipt_number'], 'reason' => $reason,
            'partial' => self::isPartial($payment), 'before' => $outcome['before'], 'after' => $outcome['after'], 'request_state' => $outcome['declaration'],
            'relocked' => $outcome['relocked'],
        ], $ip);
        if ($status === SubscriptionPayments::SUCCEEDED) {
            $this->notifyCancelled($payment, $reason, $outcome['balance_xaf'], $outcome['relocked']);
        }

        return $this->payments->present($this->payments->findOrFail($tenantId, $paymentId));
    }

    /**
     * Paiement partiel d'une déclaration de régularisation (porté au crédit du solde, sans mois entiers couverts).
     *
     * @param array<string, mixed> $payment
     */
    public static function isPartial(array $payment): bool
    {
        return (int) $payment['months'] === 0 && str_starts_with((string) $payment['reference'], 'PRT-');
    }

    /**
     * Recalcule la situation de l'application sans le paiement annulé (déjà marqué annulé dans la transaction).
     *
     * @param array<string, mixed> $payment
     * @return array{before: array<string, mixed>, after: array<string, mixed>, relocked: ?string, balance_xaf: int}
     */
    private function revertCoverage(array $payment, string $now): array
    {
        $tenantId = (string) $payment['tenant_id'];
        $entitlement = $this->entitlements->rowOrFail($tenantId, (string) $payment['app_key']);
        $applicationId = (string) $entitlement['id'];
        if ($payment['covered_until'] !== null && $entitlement['paid_until'] !== $payment['covered_until']) {
            throw HttpException::conflict('Un paiement plus récent couvre cette application : annulez d\'abord le plus récent.');
        }
        $later = $this->db->scalar(
            'SELECT COUNT(*) FROM platform_payments WHERE tenant_id = ? AND tenant_application_id = ? AND status = ? AND (created_at > ? OR (created_at = ? AND id > ?))',
            [$tenantId, $applicationId, SubscriptionPayments::SUCCEEDED, (string) $payment['created_at'], (string) $payment['created_at'], (string) $payment['id']],
        );
        if (is_numeric($later) && (int) $later > 0) {
            throw HttpException::conflict('Un paiement plus récent existe pour cette application : annulez d\'abord le plus récent.');
        }

        // Paiements restants : les paiements ordinaires fixent la couverture du dernier cycle payé ; les paiements partiels faits
        // depuis le dernier paiement ordinaire forment le crédit du cycle impayé (et, au prorata, sa prolongation).
        $base = null;
        $partials = [];
        foreach ($this->db->all('SELECT * FROM platform_payments WHERE tenant_id = ? AND tenant_application_id = ? AND status = ? ORDER BY created_at, id', [$tenantId, $applicationId, SubscriptionPayments::SUCCEEDED]) as $row) {
            if (self::isPartial($row)) {
                $partials[] = $row;
                continue;
            }
            $partials = [];
            $base = self::later($base, $row['covered_until']);
        }
        $credit = 0;
        $paidUntil = $base;
        foreach ($partials as $row) {
            $credit += (int) $row['amount_xaf'];
            $paidUntil = self::later($paidUntil, $row['covered_until']);
        }
        $anchor = $credit > 0 ? Subscription::dueOn($base, (string) $entitlement['trial_ends_on']) : null;
        $values = ['paid_until' => $paidUntil, 'regularisation_credit_xaf' => $credit > 0 ? $credit : null, 'regularisation_credit_anchor' => $anchor, 'updated_at' => $now];

        $relocked = null;
        $state = (string) $entitlement['state'];
        if (in_array($state, [ApplicationAccess::TRIAL, ApplicationAccess::ACTIVE, ApplicationAccess::PAST_DUE], true)) {
            $today = $this->clock->today();
            // Avec un crédit partiel, la prolongation au prorata ne lève pas le blocage : l'échéance qui compte est celle du cycle impayé.
            $dueOn = $anchor ?? Subscription::dueOn($paidUntil, (string) $entitlement['trial_ends_on']);
            $promise = $entitlement['promise_until'] === null ? null : (string) $entitlement['promise_until'];
            $next = Subscription::nextState(ApplicationAccess::ACTIVE, $today, $dueOn, $this->entitlements->graceDaysOf($entitlement), $promise);
            if ($next === ApplicationAccess::SUSPENDED) {
                // Mêmes valeurs que le blocage automatique de la commande quotidienne (SubscriptionBilling).
                $relocked = $this->entitlements->lockLevelOf($entitlement);
                $values += [
                    'state' => ApplicationAccess::SUSPENDED, 'past_due_since' => Calendar::addDays($dueOn, 1), 'suspended_at' => $now, 'state_reason' => 'unpaid',
                    'regularisation' => $relocked, 'regularisation_origin' => Regularisation::ORIGIN_AUTO, 'regularisation_since' => $today,
                    'promise_until' => null, 'promise_reason' => null,
                ];
            } elseif ($next === ApplicationAccess::PAST_DUE) {
                $values += ['state' => ApplicationAccess::PAST_DUE, 'past_due_since' => Calendar::addDays($dueOn, 1)];
            } else {
                $values += ['state' => $paidUntil === null ? ApplicationAccess::TRIAL : ApplicationAccess::ACTIVE, 'past_due_since' => null];
            }
        }
        $this->db->update('tenant_applications', $values, ['tenant_id' => $tenantId, 'id' => $applicationId]);
        $after = $this->entitlements->rowOrFail($tenantId, (string) $payment['app_key']);
        $months = $after['billing_period_months'] === null ? 1 : (int) $after['billing_period_months'];

        return [
            'before' => self::snapshot($entitlement),
            'after' => self::snapshot($after),
            'relocked' => $relocked,
            'balance_xaf' => max(0, $this->entitlements->totalOf($after) * $months - $credit),
        ];
    }

    private static function later(?string $current, mixed $candidate): ?string
    {
        if (!is_string($candidate) || $candidate === '') {
            return $current;
        }

        return $current === null || strcmp($candidate, $current) > 0 ? $candidate : $current;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function snapshot(array $row): array
    {
        return [
            'state' => $row['state'], 'regularisation' => $row['regularisation'] ?? null, 'paid_until' => $row['paid_until'],
            'credit_xaf' => (int) ($row['regularisation_credit_xaf'] ?? 0), 'credit_anchor' => $row['regularisation_credit_anchor'] ?? null,
        ];
    }

    /** @param array<string, mixed> $payment */
    private function notifyCancelled(array $payment, string $reason, ?int $balance, ?string $relocked): void
    {
        $tenantId = (string) $payment['tenant_id'];
        $entitlement = $this->entitlements->present($this->entitlements->rowOrFail($tenantId, (string) $payment['app_key']));
        $details = 'Motif : ' . rtrim($reason, '. ') . '.';
        if ($balance !== null && $balance > 0) {
            $details .= sprintf(' Solde restant : %d FCFA.', $balance);
        }
        if ($relocked !== null) {
            $details .= $relocked === Regularisation::LOCKED ? ' Accès bloqué jusqu\'à la régularisation.' : ' Accès en lecture seule jusqu\'à la régularisation.';
        }
        $this->notifier->toClient($tenantId, 'platform_payment_cancelled', [
            'label' => (string) $entitlement['label'], 'establishment_name' => $this->entitlements->tenantName($tenantId),
            'amount' => (int) $payment['amount_xaf'], 'receipt_number' => (string) ($payment['receipt_number'] ?? ''), 'details' => $details,
        ]);
    }
}
