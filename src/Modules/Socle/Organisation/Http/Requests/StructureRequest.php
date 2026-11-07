<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Http\Requests;

use Scholaris\Modules\Socle\Organisation\Domain\StructureKind;

final class StructureRequest extends ValidatedRequest
{
    protected function fields(): array
    {
        return [
            'kind' => Field::enum(array_map(static fn (StructureKind $kind): string => $kind->value, StructureKind::cases()))->required(),
            'name' => Field::string(160)->required(),
            'org_unit_id' => Field::reference()->nullable(),
            'parent_id' => Field::reference()->nullable(),
            'capacity' => Field::int(1, 10000)->nullable(),
        ];
    }
}
