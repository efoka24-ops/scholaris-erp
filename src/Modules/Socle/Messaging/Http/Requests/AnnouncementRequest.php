<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Http\Requests;

/** Annonce d'établissement : titre, texte et date d'expiration facultative. */
final class AnnouncementRequest extends ValidatedRequest
{
    protected function fields(): array
    {
        return [
            'title' => Field::string(160)->required(),
            'body' => Field::string(5000)->required(),
            'expires_at' => Field::dateTime()->nullable(),
        ];
    }
}
