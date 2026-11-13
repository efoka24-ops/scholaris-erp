<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Http\Requests;

final class OrgUnitRequest extends ValidatedRequest
{
    protected function fields(): array
    {
        return [
            'type' => Field::string(40)->required(),
            'name' => Field::string(160)->required(),
            'parent_id' => Field::reference()->nullable(),
        ];
    }
}
