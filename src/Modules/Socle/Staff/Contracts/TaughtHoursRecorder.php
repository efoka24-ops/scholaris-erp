<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Staff\Contracts;

/**
 * Contrat entrant : Scheduling ou Attendance signalent une séance tenue. L'heure est enregistrée « déclarée » ;
 * elle n'est payable qu'après signature de l'enseignant et validation du responsable. Idempotent par (enseignant, séance, date).
 */
interface TaughtHoursRecorder
{
    /** @return string identifiant de la ligne d'heures */
    public function record(string $tenantId, string $establishmentId, string $staffId, string $slotId, string $date, int $minutes): string;
}
