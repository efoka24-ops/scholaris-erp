<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Application;

use PDOException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Billing\Domain\PaymentChannel;
use Scholaris\Modules\Socle\Billing\Infrastructure\Db;
use Scholaris\Modules\Socle\Billing\Infrastructure\PaymentRepository;
use Scholaris\Modules\Socle\Billing\Infrastructure\ReconciliationRepository;
use Scholaris\Modules\Socle\Integrations\Contracts\PaymentGateway;
use Scholaris\Modules\Socle\Integrations\Contracts\PaymentWebhookEvent;

/**
 * Webhook de l'agrégateur de paiement : la passerelle vérifie la signature et traduit le message en événement
 * normalisé AVANT tout traitement ; un rejeu est ignoré (event_id unique) ; le traitement est idempotent.
 * Un paiement n'est jamais crédité deux fois, quel que soit le nombre de livraisons.
 */
final class WebhookService
{
    public function __construct(
        private readonly Db $db,
        private readonly PaymentGateway $gateway,
        private readonly PaymentRepository $payments,
        private readonly ReconciliationRepository $events,
        private readonly PaymentService $service,
        private readonly BillingClock $clock,
    ) {
    }

    /**
     * @param array<string, string> $headers en-têtes de la requête, noms en minuscules
     * @return array{status: string, payment_id?: string}
     */
    public function handle(string $provider, string $payload, array $headers): array
    {
        $event = $this->gateway->parseWebhook($payload, $headers);

        try {
            return $this->db->transaction(function () use ($provider, $event): array {
                $eventRowId = (string) new Ulid();
                $this->events->insert('webhook_events', [
                    'id' => $eventRowId, 'provider' => $provider, 'event_id' => $event->eventId, 'tenant_id' => null,
                    'outcome' => 'processing', 'payment_id' => null, 'received_at' => $this->clock->now(),
                ]);
                [$outcome, $tenantId, $paymentId] = $this->process($provider, $event);
                $this->events->finishWebhookEvent($eventRowId, $outcome, $tenantId, $paymentId);

                return ['status' => $outcome] + ($paymentId === null ? [] : ['payment_id' => $paymentId]);
            });
        } catch (PDOException $e) {
            if ($this->db->isUniqueViolation($e) && $this->events->webhookEvent($provider, $event->eventId) !== null) {
                return ['status' => 'duplicate'];
            }
            throw $e;
        }
    }

    /**
     * @return array{0: string, 1: string|null, 2: string|null} issue, client, paiement
     */
    private function process(string $provider, PaymentWebhookEvent $event): array
    {
        // Un statut intermédiaire n'est jamais une confirmation ni un échec : on attend l'événement suivant.
        if ($event->type === PaymentWebhookEvent::PENDING) {
            return ['ignored', null, null];
        }

        $pending = $this->payments->pendingByExternal($event->providerReference);
        if (count($pending) > 1) {
            return ['ambiguous', null, null];
        }
        if (count($pending) === 1) {
            $payment = $pending[0];
            $tenantId = (string) $payment['tenant_id'];
            if ($event->type !== PaymentWebhookEvent::SUCCEEDED) {
                $this->service->fail($tenantId, (string) $payment['id']);

                return ['failed', $tenantId, (string) $payment['id']];
            }
            if ((int) $payment['amount_xaf'] !== $event->amountXaf) {
                return ['amount_mismatch', $tenantId, (string) $payment['id']];
            }
            $this->service->confirmPending($tenantId, $payment);

            return ['confirmed', $tenantId, (string) $payment['id']];
        }

        if ($event->type !== PaymentWebhookEvent::SUCCEEDED) {
            return ['ignored', null, null];
        }
        $reference = $event->reference === null ? null : $this->payments->referenceByValue($event->reference);
        if ($reference === null) {
            // Aucun client identifiable : l'événement est conservé pour le rapprochement, rien n'est crédité.
            return ['unmatched', null, null];
        }

        $tenantId = (string) $reference['tenant_id'];
        $known = $this->payments->paymentByExternal($tenantId, $event->providerReference);
        if ($known !== null) {
            return ['already_recorded', $tenantId, (string) $known['id']];
        }

        $channel = PaymentChannel::tryFrom($event->channel ?? $provider);
        $result = $this->service->record(new RecordPayment(
            $tenantId,
            (string) $reference['establishment_id'],
            null,
            $channel !== null && $channel->isMobile() ? $channel : PaymentChannel::OrangeMoney,
            $event->amountXaf,
            'webhook:' . $provider . ':' . $event->eventId,
            (string) $reference['reference'],
            externalReference: $event->providerReference,
            payerPhone: $event->phone,
        ));

        return ['confirmed', $tenantId, (string) $result->payment['id']];
    }
}
