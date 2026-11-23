<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Http\Resources;

final class RoomResource extends Resource
{
    protected function fields(): array
    {
        return ['id', 'establishment_id', 'name', 'capacity', 'equipment', 'created_at', 'updated_at'];
    }

    protected function jsonFields(): array
    {
        return ['equipment'];
    }
}
