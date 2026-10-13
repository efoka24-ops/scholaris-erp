<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Http\Resources;

final class PeriodResource extends Resource
{
    protected function fields(): array
    {
        return ['id', 'establishment_id', 'academic_year_id', 'kind', 'ordinal', 'starts_on', 'ends_on', 'created_at', 'updated_at'];
    }
}
