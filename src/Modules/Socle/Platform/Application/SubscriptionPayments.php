<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Platform\Application;

use PDOException;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Numbering\SequenceService;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Integrations\Contracts\PaymentGateway;
use Scholaris\Modules\Socle\Integrations\Contracts\PaymentWebhookEvent;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;
use Scholaris\Modules\Socle\Platform\Domain\Calendar;
use Scholaris\Modules\Socle\Platform\Domain\Subscription;
use Scholaris\Modules\Socle\Platform\Infrastructure\PlatformDb;

/**
 * Paiement d'abonnement par mobile money (passerelle du socle, simulateur par défaut). Aucune facture Billing :
 * un paiement confirmé par webhook prolonge la période couverte, réactive une application suspendue et donne un reçu simple.
 */
final class SubscriptionPayments
{
    public const PENDING = 'pending';
    public const SUCCEEDED = 'succeeded';
    public const FAILED = 'failed';
    public const CHANNELS = ['mtn_momo', 'orange_money'];
    public const MAX_MONTHS = 12;

    public function __construct(
        private readonly PlatformDb $db,
        private readonly EntitlementService $entitlements,
        private readonly PaymentGateway $gateway,
        private readonly SequenceService $sequences,
        private readonly PlatformNotifier $notifier,
        private readonly PlatformAudit $audit,
        private readonly PlatformClock $clock,
    ) {
    }

    /**
     * Demande de paiement de $months mois au tarif effectif. Avec la même clé d'idempotence, renvoie le paiement déjà créé.
     *
     * @return array{0: array<string, mixed>, 1: bool} [paiement, créé maintenant]
     */
    public function initiate(string $tenantId, string $appKey, int $months, string $channel, string $payerPhone, string $actorId, ?string $idempotencyKey): array
    {
        if ($idempotencyKey !== null) {
            $existing = $this->db->one('SELECT * FROM platform_payments WHERE tenant_id = ? AND idempotency_key = ?', [$tenantId, $idempotencyKey]);
            if ($existing !== null) {
                return [$this->present($existing), false];
            }
        }
        $entitlement = $this->entitlements->rowOrFail($tenantId, $appKey);
        if ($entitlement['state'] === ApplicationAccess::CLOSED) {
            throw HttpException::conflict('Cette application est fermée : demandez sa réouverture.');
        }
        $price = $this->entitlements->priceOf($entitlement);
        if ($price <= 0) {
            throw HttpException::conflict('Aucun tarif n\'est défini pour cette application.');
        }

        $id = (string) new Ulid();
        $reference = 'PLT-' . $id;
        $amount = $price * $months;
        $now = $this->clock->iso();
        try {
            $this->db->insert('platform_payments', [
                'id' => $id,
                'tenant_id' => $tenantId,
                'tenant_application_id' => (string) $entitlement['id'],
                'app_key' => $appKey,
                'reference' => $reference,
                'provider_reference' => null,
                'idempotency_key' => $idempotencyKey,
                'amount_xaf' => $amount,
                'months' => $months,
                'channel' => $channel,
                'payer_phone' => $payerPhone,
                'status' => self::PENDING,
                'receipt_number' => null,
                'covered_from' => null,
                'covered_until' => null,
                'initiated_by' => $actorId,
                'paid_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (PDOException) {
            throw HttpException::conflict('Une demande de paiement avec cette clé d\'idempotence est en cours.');
        }

        try {
            $started = $this->gateway->initiate($amount, $channel, $payerPhone, $reference);
        } catch (HttpException $e) {
            $this->db->update('platform_payments', ['status' => self::FAILED, 'updated_at' => $this->clock->iso()], ['tenant_id' => $tenantId, 'id' => $id]);
            throw $e;
        }
        $this->db->update('platform_payments', ['provider_reference' => $started['provider_reference'], 'updated_at' => $this->clock->iso()], ['tenant_id' => $tenantId, 'id' => $id]);
        $this->audit->record($tenantId, $actorId, 'platform.payment_initiated', 'platform_payment', $id, ['app_key' => $appKey, 'amount_xaf' => $amount, 'months' => $months]);

        return [$this->present($this->findOrFail($tenantId, $id)), true];
    }

    /**
     * Webhook de l'agrégateur : signature vérifiée par la passerelle ; un même événement n'est appliqué qu'une fois.
     *
     * @param array<string, string> $headers
     * @return array{event_id: string, outcome: string}
     */
    public function handleWebhook(string $rawBody, array $headers): array
    {
        $event = $this->gateway->parseWebhook($rawBody, $headers);
        $now = $this->clock->iso();

        $result = $this->db->transaction(function () use ($event, $now): array {
            try {
                $this->db->insert('platform_payment_events', ['event_id' => $event->eventId, 'payment_id' => null, 'outcome' => 'processing', 'received_at' => $now]);
            } catch (PDOException) {
                return ['outcome' => 'duplicate', 'payment' => null];
            }
            $payment = $this->db->one('SELECT * FROM platform_payments WHERE provider_reference = ?', [$event->providerReference]);
            $outcome = match (true) {
                $payment === null => 'unknown_reference',
                $payment['status'] !== self::PENDING => 'already_' . $payment['status'],
                $event->type === PaymentWebhookEvent::PENDING => 'pending',
                $event->type === PaymentWebhookEvent::FAILED => $this->fail($payment, $now),
                $event->amountXaf !== (int) $payment['amount_xaf'] => 'amount_mismatch',
                default => $this->succeed($payment, $now),
            };
            $this->db->update('platform_payment_events', ['outcome' => $outcome, 'payment_id' => $payment === null ? null : (string) $payment['id']], ['event_id' => $event->eventId]);

            return ['outcome' => $outcome, 'payment' => $payment];
        });

        $payment = $result['payment'];
        if (is_array($payment)) {
            $tenantId = (string) $payment['tenant_id'];
            $this->audit->record($tenantId, null, 'platform.payment_webhook', 'platform_payment', (string) $payment['id'], ['event_id' => $event->eventId, 'outcome' => $result['outcome']]);
            if ($result['outcome'] === 'succeeded') {
                $this->sendReceipt($this->findOrFail($tenantId, (string) $payment['id']));
            }
        }

        return ['event_id' => $event->eventId, 'outcome' => (string) $result['outcome']];
    }

    /** @return list<array<string, mixed>> */
    public function forApplication(string $tenantId, string $appKey): array
    {
        return array_map(fn (array $row): array => $this->present($row), $this->db->all(
            'SELECT * FROM platform_payments WHERE tenant_id = ? AND app_key = ? ORDER BY created_at DESC, id DESC LIMIT 200',
            [$tenantId, $appKey],
        ));
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: ?string}
     */
    public function page(?string $status, ?string $tenantId, ?string $afterId, int $perPage): array
    {
        $sql = 'SELECT * FROM platform_payments WHERE 1 = 1';
        $params = [];
        foreach (['status' => $status, 'tenant_id' => $tenantId] as $column => $value) {
            if ($value !== null) {
                $sql .= ' AND ' . $column . ' = ?';
                $params[] = $value;
            }
        }
        // Le plus récent d'abord : le curseur désigne le dernier paiement de la page précédente.
        if ($afterId !== null) {
            $sql .= ' AND id < ?';
            $params[] = $afterId;
        }
        $rows = $this->db->all($sql . ' ORDER BY id DESC LIMIT ' . ($perPage + 1), $params);
        $next = null;
        if (count($rows) > $perPage) {
            $rows = array_slice($rows, 0, $perPage);
            $next = (string) $rows[$perPage - 1]['id'];
        }

        return [array_map(fn (array $row): array => $this->present($row), $rows), $next];
    }

    /** @return array<string, mixed> reçu simple d'un paiement confirmé du client */
    public function receipt(string $tenantId, string $paymentId): array
    {
        $payment = $this->findOrFail($tenantId, $paymentId);
        if ($payment['status'] !== self::SUCCEEDED) {
            throw HttpException::conflict('Ce paiement n\'est pas confirmé : aucun reçu.');
        }
        $label = $this->entitlements->present($this->entitlements->rowOrFail($tenantId, (string) $payment['app_key']))['label'];

        return [
            'receipt_number' => (string) $payment['receipt_number'],
            'issuer' => 'SCHOLARIS',
            'tenant_id' => $tenantId,
            'app_key' => (string) $payment['app_key'],
            'app_label' => $label,
            'amount_xaf' => (int) $payment['amount_xaf'],
            'months' => (int) $payment['months'],
            'covered_from' => $payment['covered_from'],
            'covered_until' => $payment['covered_until'],
            'channel' => (string) $payment['channel'],
            'reference' => (string) $payment['reference'],
            'paid_at' => $payment['paid_at'],
        ];
    }

    /** Somme des paiements confirmés depuis une date (ISO), en XAF. */
    public function collectedSince(string $since): int
    {
        return (int) $this->db->scalar('SELECT COALESCE(SUM(amount_xaf), 0) FROM platform_payments WHERE status = ? AND paid_at >= ?', [self::SUCCEEDED, $since]);
    }

    /** @param array<string, mixed> $payment */
    private function fail(array $payment, string $now): string
    {
        $this->db->update('platform_payments', ['status' => self::FAILED, 'updated_at' => $now], ['tenant_id' => (string) $payment['tenant_id'], 'id' => (string) $payment['id']]);

        return 'failed';
    }

    /** @param array<string, mixed> $payment */
    private function succeed(array $payment, string $now): string
    {
        $tenantId = (string) $payment['tenant_id'];
        $entitlement = $this->entitlements->rowOrFail($tenantId, (string) $payment['app_key']);
        $today = $this->clock->today();
        $dueOn = Subscription::dueOn($entitlement['paid_until'] === null ? null : (string) $entitlement['paid_until'], (string) $entitlement['trial_ends_on']);
        $coverage = Subscription::coverage($today, $dueOn, (int) $payment['months']);
        $year = $this->clock->year();
        $receipt = SequenceService::format('SCH', $year, $this->sequences->next(PlatformAudit::PLATFORM_TENANT, PlatformAudit::PLATFORM_TENANT, 'platform_receipt', $year));

        $this->db->update('platform_payments', [
            'status' => self::SUCCEEDED, 'receipt_number' => $receipt, 'covered_from' => $coverage['from'], 'covered_until' => $coverage['until'],
            'paid_at' => $now, 'updated_at' => $now,
        ], ['tenant_id' => $tenantId, 'id' => (string) $payment['id']]);

        $values = ['paid_until' => $coverage['until'], 'updated_at' => $now];
        if ($entitlement['state'] !== ApplicationAccess::CLOSED) {
            $values += ['state' => ApplicationAccess::ACTIVE, 'past_due_since' => null, 'suspended_at' => null, 'state_reason' => null];
        }
        $this->db->update('tenant_applications', $values, ['tenant_id' => $tenantId, 'id' => (string) $entitlement['id']]);

        return 'succeeded';
    }

    /** @param array<string, mixed> $payment */
    public function sendReceipt(array $payment): void
    {
        $tenantId = (string) $payment['tenant_id'];
        $entitlement = $this->entitlements->present($this->entitlements->rowOrFail($tenantId, (string) $payment['app_key']));
        $this->notifier->toClient($tenantId, 'platform_payment_receipt', [
            'receipt_number' => (string) $payment['receipt_number'],
            'amount' => (int) $payment['amount_xaf'],
            'label' => (string) $entitlement['label'],
            'establishment_name' => $this->entitlements->tenantName($tenantId),
            'due_on' => Calendar::display((string) $payment['covered_until']),
        ]);
    }

    /** @return array<string, mixed> */
    public function findOrFail(string $tenantId, string $id): array
    {
        return $this->db->one('SELECT * FROM platform_payments WHERE tenant_id = ? AND id = ?', [$tenantId, $id]) ?? throw HttpException::notFound('Paiement introuvable.');
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function present(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'tenant_id' => (string) $row['tenant_id'],
            'app_key' => (string) $row['app_key'],
            'reference' => (string) $row['reference'],
            'amount_xaf' => (int) $row['amount_xaf'],
            'months' => (int) $row['months'],
            'channel' => (string) $row['channel'],
            'status' => (string) $row['status'],
            'receipt_number' => $row['receipt_number'],
            'covered_from' => $row['covered_from'],
            'covered_until' => $row['covered_until'],
            'paid_at' => $row['paid_at'],
            'note' => $row['payment_note'] ?? null,
            'cancelled_at' => $row['cancelled_at'] ?? null,
            'cancel_reason' => $row['cancel_reason'] ?? null,
            'created_at' => (string) $row['created_at'],
        ];
    }
}
