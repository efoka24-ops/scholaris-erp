<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Http\Resources;

final class EstablishmentResource extends Resource
{
    protected function fields(): array
    {
        return ['id', 'name', 'type', 'establishment_subtype', 'branding', 'modules_enabled', 'created_at', 'updated_at'];
    }

    protected function jsonFields(): array
    {
        return ['branding', 'modules_enabled'];
    }
}
