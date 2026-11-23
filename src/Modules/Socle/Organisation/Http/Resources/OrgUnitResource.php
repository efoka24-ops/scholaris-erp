<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Http\Resources;

final class OrgUnitResource extends Resource
{
    protected function fields(): array
    {
        return ['id', 'establishment_id', 'parent_id', 'type', 'name', 'created_at', 'updated_at'];
    }
}
