<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Domain;

/** Tranche restant à payer (montant dû non encore couvert), vue par le moteur d'affectation. */
final class Due
{
    public function __construct(
        public readonly string $installmentId,
        public readonly string $invoiceId,
        public readonly string $learnerId,
        public readonly string $establishmentId,
        /** registration, tuition ou other */
        public readonly string $kind,
        public readonly string $dueOn,
        public readonly int $outstanding,
        public readonly int $sequence = 0,
    ) {
    }

    /** Une tranche est en retard le lendemain de sa date limite. */
    public function isOverdue(string $today): bool
    {
        return $this->dueOn < $today;
    }
}
