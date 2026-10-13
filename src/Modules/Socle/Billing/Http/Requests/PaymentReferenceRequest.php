<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Http\Requests;

final class PaymentReferenceRequest extends ValidatedRequest
{
    protected function fields(): array
    {
        return ['guarantor_id' => Field::id()->required()];
    }
}
