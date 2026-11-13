<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Application;

use Scholaris\Modules\Socle\Billing\Domain\PaymentChannel;

/** Demande d'enregistrement d'un paiement (guichet, mobile money saisi, webhook, import de relevé). */
final class RecordPayment
{
    public function __construct(
        public readonly string $tenantId,
        public readonly string $establishmentId,
        public readonly ?string $actorId,
        public readonly PaymentChannel $channel,
        public readonly int $amountXaf,
        public readonly string $idempotencyKey,
        public readonly ?string $familyReference = null,
        public readonly ?string $learnerId = null,
        public readonly ?string $invoiceId = null,
        public readonly ?string $externalReference = null,
        public readonly ?string $payerPhone = null,
    ) {
    }
}
