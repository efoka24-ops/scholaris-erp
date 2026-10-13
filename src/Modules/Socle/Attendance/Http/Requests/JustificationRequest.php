<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Attendance\Http\Requests;

/** Dépôt d'un justificatif d'absence : période, motif, référence de document facultative (aucun fichier ici). */
final class JustificationRequest extends ValidatedRequest
{
    protected function fields(): array
    {
        return [
            'person_id' => Field::id()->required(),
            'absence_from' => Field::date()->required(),
            'absence_to' => Field::date()->required(),
            'reason' => Field::string(255)->required(),
            'document_ref' => Field::string(80),
        ];
    }
}
