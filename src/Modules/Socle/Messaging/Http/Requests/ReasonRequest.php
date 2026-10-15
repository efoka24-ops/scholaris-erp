<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Http\Requests;

/** Motif obligatoire d'un signalement ou d'un masquage. */
final class ReasonRequest extends ValidatedRequest
{
    protected function fields(): array
    {
        return ['reason' => Field::string(160, 3)->required()];
    }
}
