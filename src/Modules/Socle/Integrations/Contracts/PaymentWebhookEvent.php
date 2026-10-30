<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Integrations\Contracts;

/** Événement de paiement normalisé, indépendant de l'agrégateur qui l'a émis. */
final class PaymentWebhookEvent
{
    public const SUCCEEDED = 'payment.succeeded';
    public const FAILED = 'payment.failed';
    public const PENDING = 'payment.pending';

    public function __construct(
        /** Identifiant unique de la livraison, base de l'idempotence (un rejeu ne crédite jamais deux fois). */
        public readonly string $eventId,
        /** SUCCEEDED, FAILED ou PENDING */
        public readonly string $type,
        public readonly int $amountXaf,
        /** Référence unique de la transaction côté application (celle envoyée à l'agrégateur). */
        public readonly string $providerReference,
        /** Référence famille connue de l'opérateur, si présente. */
        public readonly ?string $reference = null,
        public readonly ?string $phone = null,
        public readonly ?string $channel = null,
    ) {
    }
}
