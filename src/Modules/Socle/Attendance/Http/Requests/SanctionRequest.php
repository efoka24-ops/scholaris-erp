<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Attendance\Http\Requests;

/** Proposition de sanction ; le type doit figurer au barème de l'établissement (contrôlé par le service). */
final class SanctionRequest extends ValidatedRequest
{
    protected function fields(): array
    {
        return [
            'person_id' => Field::id()->required(),
            'kind' => Field::string(40)->required(),
            'reason' => Field::string(255)->required(),
            'incident_id' => Field::id(),
        ];
    }
}
