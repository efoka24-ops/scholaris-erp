<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Http\Resources;

final class StructureResource extends Resource
{
    protected function fields(): array
    {
        return ['id', 'establishment_id', 'org_unit_id', 'parent_id', 'kind', 'name', 'capacity', 'created_at', 'updated_at'];
    }
}
