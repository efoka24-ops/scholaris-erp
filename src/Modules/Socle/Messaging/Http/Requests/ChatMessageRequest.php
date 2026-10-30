<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Http\Requests;

use Scholaris\Modules\Socle\Messaging\Application\ChatService;

/** Message d'une conversation : texte et pièces jointes par référence de document. */
final class ChatMessageRequest extends ValidatedRequest
{
    protected function fields(): array
    {
        return [
            'body' => Field::string(5000)->required(),
            'attachments' => ConversationRequest::idList(ChatService::MAX_ATTACHMENTS),
        ];
    }
}
