<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Http\Requests;

/** Aperçu d'audience : seule la cible est attendue. */
final class AudiencePreviewRequest extends ValidatedRequest
{
    protected function fields(): array
    {
        return ['audience' => MessageRequest::audienceField()];
    }
}
