<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Attendance\Http\Requests;

/** Création de l'appel d'un créneau pour un jour. Les lignes peuvent être jointes (appel complet en un envoi). */
final class SessionRequest extends ValidatedRequest
{
    protected function fields(): array
    {
        return [
            'client_uuid' => Field::ulid()->required(),
            'slot_id' => Field::id()->required(),
            'date' => Field::date()->required(),
            'records' => Field::listOf(new RecordLineRequest(), 500),
            'validate' => Field::bool(),
        ];
    }
}
