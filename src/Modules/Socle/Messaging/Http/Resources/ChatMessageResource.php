<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Http\Resources;

/**
 * Message de conversation. Un message masqué n'expose ni son texte ni ses pièces jointes aux participants ;
 * seul un modérateur voit le contenu, l'auteur de la modération et le motif.
 */
final class ChatMessageResource extends Resource
{
    public function __construct(private readonly bool $moderator = false)
    {
    }

    protected function fields(): array
    {
        return ['id', 'conversation_id', 'sender_id', 'body', 'attachments', 'moderation_status', 'created_at'];
    }

    protected function jsonFields(): array
    {
        return ['attachments'];
    }

    /** @param array<string, mixed> $row */
    public function toArray(array $row): array
    {
        $out = parent::toArray($row);
        $hidden = ($row['moderation_status'] ?? 'visible') === 'hidden';
        $out['attachments'] = $out['attachments'] ?? [];
        if ($hidden && !$this->moderator) {
            $out['body'] = null;
            $out['attachments'] = [];
        }
        if ($this->moderator) {
            $out['moderated_by'] = $row['moderated_by'] ?? null;
            $out['moderated_at'] = $row['moderated_at'] ?? null;
            $out['moderation_reason'] = $row['moderation_reason'] ?? null;
        }

        return $out;
    }
}
