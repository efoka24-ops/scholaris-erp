<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Contracts;

/** Résultat d'une demande de paiement : statut (pending, confirmed, failed, reversed), reçu numéroté une fois confirmé. */
final class PaymentOutcome
{
    public const PENDING = 'pending';
    public const CONFIRMED = 'confirmed';

    public function __construct(
        public readonly string $paymentId,
        public readonly string $status,
        public readonly int $amountXaf,
        public readonly string $channel,
        public readonly ?string $invoiceId,
        public readonly ?string $receiptId,
        public readonly ?string $receiptNumber,
        public readonly ?string $externalReference,
        public readonly bool $replayed = false,
    ) {
    }
}
