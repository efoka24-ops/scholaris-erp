<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Http\Resources;

final class ConversationReportResource extends Resource
{
    protected function fields(): array
    {
        return ['id', 'conversation_id', 'message_id', 'reported_by', 'reason', 'status', 'resolved_by', 'resolved_at', 'created_at'];
    }
}
