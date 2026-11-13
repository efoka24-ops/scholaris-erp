<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Contracts;

/**
 * Ligne d'une facture libre (D4) : un frais du catalogue désigné par son code stable (S10) ou son identifiant, montant et libellé
 * repris du catalogue s'ils ne sont pas fournis, une seule échéance.
 */
final class ChargeLine
{
    public function __construct(
        public readonly ?string $feeCode,
        public readonly string $dueOn,
        public readonly ?int $amountXaf = null,
        public readonly ?string $label = null,
        public readonly ?string $feeItemId = null,
    ) {
    }
}
