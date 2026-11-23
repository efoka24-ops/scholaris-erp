<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Http\Resources;

final class AcademicYearResource extends Resource
{
    protected function fields(): array
    {
        return ['id', 'establishment_id', 'label', 'starts_on', 'ends_on', 'status', 'closed_at', 'created_at', 'updated_at'];
    }
}
