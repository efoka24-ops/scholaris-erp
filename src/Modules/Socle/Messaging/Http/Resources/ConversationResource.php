<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Http\Resources;

final class ConversationResource extends Resource
{
    protected function fields(): array
    {
        return ['id', 'subject', 'kind', 'learner_id', 'status', 'created_by', 'last_message_at', 'archived_at', 'created_at', 'updated_at'];
    }

    /** @param array<string, mixed> $row */
    public function toArray(array $row): array
    {
        $out = parent::toArray($row);
        if (array_key_exists('unread_count', $row)) {
            $out['unread_count'] = (int) $row['unread_count'];
        }

        return $out;
    }
}
