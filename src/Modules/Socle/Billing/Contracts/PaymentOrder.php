<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Contracts;

/**
 * Demande de paiement d'une facture (D4) : guichet (`cash`, caisse ouverte de l'acteur), mobile money (`orange_money`, `mtn_momo` :
 * en attente de confirmation de l'opérateur si aucune référence externe n'est fournie), virement, carte. La clé d'idempotence
 * garantit qu'un rejeu ne crédite pas deux fois.
 */
final class PaymentOrder
{
    public function __construct(
        public readonly string $tenantId,
        public readonly string $establishmentId,
        public readonly ?string $actorId,
        public readonly string $invoiceId,
        public readonly int $amountXaf,
        public readonly string $channel,
        public readonly string $idempotencyKey,
        public readonly ?string $payerPhone = null,
        public readonly ?string $externalReference = null,
    ) {
    }
}
