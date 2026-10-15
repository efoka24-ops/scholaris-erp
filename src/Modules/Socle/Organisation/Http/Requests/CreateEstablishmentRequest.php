<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Http\Requests;

use Scholaris\Modules\Socle\Organisation\Domain\EstablishmentType;

final class CreateEstablishmentRequest extends ValidatedRequest
{
    protected function fields(): array
    {
        return [
            'name' => Field::string(160)->required(),
            'type' => Field::enum(EstablishmentType::values())->required(),
        ];
    }
}
