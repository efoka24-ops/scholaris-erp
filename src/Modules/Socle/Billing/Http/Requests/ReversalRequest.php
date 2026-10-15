<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Http\Requests;

/** Demande d'annulation d'un paiement : le motif est obligatoire. */
final class ReversalRequest extends ValidatedRequest
{
    protected function fields(): array
    {
        return [
            'payment_id' => Field::id()->required(),
            'reason' => Field::string(255)->required(),
        ];
    }
}
