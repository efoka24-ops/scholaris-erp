<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Attendance\Http\Requests;

use Scholaris\Modules\Socle\Attendance\Contracts\AttendanceCodes;

/** Un appel enregistré hors ligne, à synchroniser (créneau, ou classe et moment de la journée en mode day_part). */
final class SyncItemRequest extends ValidatedRequest
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
