<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Http\Requests;

/** Import d'un relevé d'opérateur (CSV dans le champ csv). */
final class ReconciliationRequest extends ValidatedRequest
{
    protected function fields(): array
    {
        return [
            'channel' => Field::enum(['orange_money', 'mtn_momo'])->required(),
            'source' => Field::string(160)->required(),
            'csv' => Field::string(1000000)->required(),
        ];
    }
}
