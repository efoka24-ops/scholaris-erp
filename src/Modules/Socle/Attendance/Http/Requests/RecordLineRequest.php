<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Attendance\Http\Requests;

use Scholaris\Modules\Socle\Attendance\Domain\AttendanceStatus;

/** Une ligne d'appel : élève, statut P/A/R, heure d'arrivée pour un retard, client_uuid de la ligne (idempotence hors ligne). */
final class RecordLineRequest extends ValidatedRequest
{
    protected function fields(): array
    {
        return [
            'person_id' => Field::id()->required(),
            'status' => Field::enum(AttendanceStatus::values())->required(),
            'arrived_at' => Field::time(),
            'client_uuid' => Field::ulid(),
            'note' => Field::string(255),
        ];
    }

    protected function crossCheck(array $values, array $errors): array
    {
        if (($values['status'] ?? null) === 'R' && !isset($values['arrived_at'])) {
            $errors['arrived_at'] = ['Un retard doit préciser l\'heure d\'arrivée (HH:MM).'];
        }
        if (($values['status'] ?? null) !== 'R' && isset($values['arrived_at'])) {
            $errors['arrived_at'] = ['L\'heure d\'arrivée ne s\'applique qu\'à un retard.'];
        }

        return $errors;
    }
}
