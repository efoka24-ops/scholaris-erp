<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Application;

/** Résultat d'un enregistrement de paiement : le paiement, son reçu (absent tant qu'il est en attente) et s'il s'agit d'un rejeu. */
final class PaymentResult
{
    /**
     * @param array<string, mixed> $payment
     * @param array<string, mixed>|null $receipt
     */
    public function __construct(
        public readonly array $payment,
        public readonly ?array $receipt,
        public readonly bool $replayed = false,
    ) {
    }
}
