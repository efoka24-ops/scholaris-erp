<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Http\Resources;

final class AnnouncementResource extends Resource
{
    protected function fields(): array
    {
        return ['id', 'title', 'body', 'published_at', 'expires_at', 'created_by', 'created_at', 'updated_at'];
    }
}
