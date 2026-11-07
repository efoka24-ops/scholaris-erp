<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Http\Resources;

final class AuthorizationResource extends Resource
{
    protected function fields(): array
    {
        return ['id', 'establishment_id', 'structure_id', 'reference', 'authority', 'valid_from', 'valid_to', 'covered_levels', 'document_id', 'created_at', 'updated_at'];
    }

    protected function jsonFields(): array
    {
        return ['covered_levels'];
    }
}
