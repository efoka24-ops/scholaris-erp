<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Integrations\Infrastructure\Sungku;

use Psr\Log\LoggerInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Integrations\Contracts\PaymentGateway;
use Scholaris\Modules\Socle\Integrations\Contracts\PaymentWebhookEvent;

/**
 * Port PaymentGateway sur Sungku (Orange Money, MTN MoMo).
 *
 * - Une référence UNIQUE est créée pour chaque demande (`SCH-<ULID>`) et envoyée à Sungku ; elle revient dans le
 *   webhook et sert de clé d'idempotence. Le montant vient du serveur, jamais du client.
 * - Refus clair de Sungku (4xx) : échec prouvé, erreur 422, aucune transaction en attente créée.
 * - Sungku injoignable (délai, 5xx, 429) : échec NON prouvé. La demande reste « en attente » avec sa référence ;
 *   l'argent a peut-être bougé, seul le webhook ou le rapprochement tranchera.
 * - Jamais de succès à l'initiation : seul le webhook signé confirme.
 */
final class SungkuPaymentGateway implements PaymentGateway
{
    public function __construct(
        private readonly SungkuClient $client,
        private readonly SungkuWebhook $webhook,
        private readonly LoggerInterface $logger,
        private readonly string $description = 'Frais de scolarité',
    ) {
    }

    public function initiate(int $amountXaf, string $channel, string $payerPhone, string $reference): array
    {
        $unique = 'SCH-' . new Ulid();

        try {
            $this->client->initiateDeposit([
                'amount' => $amountXaf,
                'currency' => 'XAF',
                'phoneNumber' => self::msisdn($payerPhone),
                'reference' => $unique,
                'description' => $this->description,
                'customerMessage' => 'Paiement ' . $reference,
                'metadata' => ['family_reference' => $reference, 'channel' => $channel],
            ]);
        } catch (SungkuRejected $e) {
            $this->logger->warning('Sungku a refusé la demande de paiement', ['reference' => $unique, 'http' => $e->httpStatus]);

            throw new HttpException(
                422,
                'Paiement refusé',
                'L\'opérateur a refusé la demande de paiement. Vérifiez le numéro et le montant, puis réessayez.',
                'https://api.scholaris.cm/errors/payment-rejected',
            );
        } catch (SungkuUnavailable $e) {
            // Échec non prouvé : on garde la référence, la transaction reste en attente.
            $this->logger->warning('Sungku indisponible, paiement laissé en attente', ['reference' => $unique, 'cause' => $e->getMessage()]);
        }

        return ['provider_reference' => $unique, 'status' => self::PENDING];
    }

    public function parseWebhook(string $rawBody, array $headers): PaymentWebhookEvent
    {
        $timestamp = $headers['x-apisungku-timestamp'] ?? '';
        $signature = $headers['x-apisungku-signature'] ?? '';
        if (!$this->webhook->verify($rawBody, $timestamp, $signature)) {
            throw new HttpException(401, 'Signature invalide', 'La signature du webhook est absente ou invalide.', 'https://api.scholaris.cm/errors/unauthorized');
        }

        $data = json_decode($rawBody, true);
        if (!is_array($data)) {
            throw HttpException::badRequest('Le corps du webhook doit être un objet JSON.');
        }

        $reference = self::text($data['reference'] ?? $data['externalReference'] ?? null);
        $status = self::text($data['status'] ?? null);
        $amount = $data['amount'] ?? null;
        $errors = [];
        if ($reference === null) {
            $errors['reference'] = ['Référence de la transaction obligatoire.'];
        }
        if (!is_numeric($amount) || (int) round((float) $amount) <= 0) {
            $errors['amount'] = ['Le montant doit être un nombre positif en XAF.'];
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        $type = match (SungkuWebhook::internalStatus((string) $status)) {
            SungkuWebhook::CONFIRMED => PaymentWebhookEvent::SUCCEEDED,
            SungkuWebhook::FAILED => PaymentWebhookEvent::FAILED,
            default => PaymentWebhookEvent::PENDING,
        };

        $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];

        return new PaymentWebhookEvent(
            // Une même transition d'état rejouée est un doublon ; un changement d'état est un nouvel événement.
            substr((string) $reference . ':' . strtoupper((string) $status), 0, 120),
            $type,
            (int) round((float) $amount),
            (string) $reference,
            self::text($metadata['family_reference'] ?? null),
            self::text($data['phoneNumber'] ?? $data['phone'] ?? null),
            self::text($metadata['channel'] ?? $data['channel'] ?? null),
        );
    }

    /** Numéro au format international sans « + » ni séparateurs (ex. 237699123456). */
    private static function msisdn(string $phone): string
    {
        return (string) preg_replace('/\D+/', '', $phone);
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? substr($value, 0, 80) : null;
    }
}
