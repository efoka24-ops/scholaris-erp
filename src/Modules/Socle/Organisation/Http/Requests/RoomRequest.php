<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Http\Requests;

final class RoomRequest extends ValidatedRequest
{
    protected function fields(): array
    {
        return [
            'name' => Field::string(120)->required(),
            'capacity' => Field::int(1, 10000)->nullable(),
            'equipment' => Field::stringList(30, 80),
        ];
    }
}
