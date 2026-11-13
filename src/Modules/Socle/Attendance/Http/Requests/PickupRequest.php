<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Attendance\Http\Requests;

/** Personne autorisée à récupérer un enfant : autorisation permanente ou du jour. */
final class PickupRequest extends ValidatedRequest
{
    protected function fields(): array
    {
        return [
            'learner_id' => Field::id()->required(),
            'pickup_name' => Field::string(160)->required(),
            'relationship' => Field::string(60)->required(),
            'phone' => Field::string(30),
            'photo_document_ref' => Field::string(80),
            'grant_type' => Field::enum(['permanent', 'day'])->required(),
            'valid_on' => Field::date(),
        ];
    }
}
