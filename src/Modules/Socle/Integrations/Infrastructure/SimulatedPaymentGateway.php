<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Integrations\Infrastructure;

use InvalidArgumentException;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Integrations\Contracts\PaymentGateway;
use Scholaris\Modules\Socle\Integrations\Contracts\PaymentWebhookEvent;

/**
 * Simulateur d'agrégateur (développement et tests) : accepte toute demande et signe les webhooks en
 * HMAC-SHA256 du corps brut (en-tête X-Signature) avec un secret local. Aucun appel réseau.
 */
final class SimulatedPaymentGateway implements PaymentGateway
{
    public function __construct(private readonly string $webhookSecret)
    {
    }

    public function initiate(int $amountXaf, string $channel, string $payerPhone, string $reference): array
    {
        if ($amountXaf <= 0) {
            throw new InvalidArgumentException('Le montant doit être un entier positif en XAF.');
        }

        return ['provider_reference' => 'sim-pay-' . new Ulid(), 'status' => self::PENDING];
    }

    public function verifySignature(string $payload, string $signature): bool
    {
        if ($this->webhookSecret === '' || $signature === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $payload, $this->webhookSecret), $signature);
    }

    public function parseWebhook(string $rawBody, array $headers): PaymentWebhookEvent
    {
        if (!$this->verifySignature($rawBody, $headers['x-signature'] ?? '')) {
            throw new HttpException(401, 'Signature invalide', 'La signature du webhook est absente ou invalide.', 'https://api.scholaris.cm/errors/unauthorized');
        }

        $data = json_decode($rawBody, true);
        if (!is_array($data)) {
            throw HttpException::badRequest('Le corps du webhook doit être un objet JSON.');
        }

        $errors = [];
        $eventId = $data['event_id'] ?? null;
        if (!is_string($eventId) || $eventId === '' || strlen($eventId) > 120) {
            $errors['event_id'] = ['Identifiant d\'événement obligatoire.'];
        }
        if (!in_array($data['type'] ?? null, [PaymentWebhookEvent::SUCCEEDED, PaymentWebhookEvent::FAILED, PaymentWebhookEvent::PENDING], true)) {
            $errors['type'] = ['Type d\'événement non reconnu.'];
        }
        if (!is_int($data['amount'] ?? null) || $data['amount'] <= 0) {
            $errors['amount'] = ['Le montant doit être un entier positif en XAF.'];
        }
        $providerReference = $data['provider_reference'] ?? null;
        if (!is_string($providerReference) || $providerReference === '' || strlen($providerReference) > 80) {
            $errors['provider_reference'] = ['Référence de l\'opérateur obligatoire.'];
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        $optional = static fn (string $key): ?string => isset($data[$key]) && is_string($data[$key]) && $data[$key] !== '' ? substr($data[$key], 0, 80) : null;

        return new PaymentWebhookEvent(
            (string) $eventId,
            (string) $data['type'],
            (int) $data['amount'],
            (string) $providerReference,
            $optional('reference'),
            $optional('phone'),
            $optional('channel'),
        );
    }
}
