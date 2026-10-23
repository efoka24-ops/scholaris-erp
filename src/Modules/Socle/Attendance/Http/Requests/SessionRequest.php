<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Attendance\Http\Requests;

use Scholaris\Modules\Socle\Attendance\Contracts\AttendanceCodes;

/**
 * Création de l'appel d'un créneau (ou, en mode day_part, d'une classe et d'un moment de la journée) pour un jour.
 * Les lignes peuvent être jointes (appel complet en un envoi).
 */
final class SessionRequest extends ValidatedRequest
{
    protected function fields(): array
    {
        return [
            'client_uuid' => Field::ulid()->required(),
            'slot_id' => Field::id(),
            'structure_id' => Field::id(),
            'day_part' => Field::enum(AttendanceCodes::DAY_PARTS),
            'date' => Field::date()->required(),
            'records' => Field::listOf(new RecordLineRequest(), 500),
            'validate' => Field::bool(),
        ];
    }

    protected function crossCheck(array $values, array $errors): array
    {
        return SessionTarget::check($values, $errors);
    }
}
