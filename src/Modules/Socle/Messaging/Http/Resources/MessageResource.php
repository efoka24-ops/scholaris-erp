<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Http\Resources;

final class MessageResource extends Resource
{
    protected function fields(): array
    {
        return ['id', 'template_key', 'body', 'channels', 'variables', 'audience', 'urgent', 'status', 'scheduled_at', 'recipients_count', 'created_by', 'created_at', 'updated_at'];
    }

    protected function jsonFields(): array
    {
        return ['channels', 'variables', 'audience'];
    }

    protected function boolFields(): array
    {
        return ['urgent'];
    }
}
