<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Contracts;

/**
 * Frais du catalogue tel qu'il serait enregistré (création, ou état après modification), soumis aux gardes `FeeItemGuard` (SS8).
 */
final class FeeItemDraft
{
    public function __construct(
        public readonly string $tenantId,
        public readonly string $establishmentId,
        /** type de frais (socle ou déclaré par une application, `billing.fee_kinds.*`) */
        public readonly string $kind,
        /** code stable facultatif (ex. code du catalogue autorisé) */
        public readonly ?string $code,
        public readonly string $name,
        public readonly int $amountXaf,
        public readonly bool $active,
        /** identifiant du frais modifié ; null pour une création */
        public readonly ?string $feeItemId = null,
    ) {
    }

    public function isCreation(): bool
    {
        return $this->feeItemId === null;
    }
}
