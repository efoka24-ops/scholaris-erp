<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Http\Requests;

/** Ouverture d'une conversation avec le support SCHOLARIS : objet et premier message (aucun autre champ). */
final class SupportConversationRequest extends ValidatedRequest
{
    protected function fields(): array
    {
        return [
            'subject' => Field::string(160)->required(),
            'body' => Field::string(5000)->required(),
        ];
    }
}
