<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Attendance\Contracts;

use Scholaris\Core\Security\Principal;

/** Écriture d’un appel par demi-journée, bornée au client de l’acteur et à l’établissement indiqué. */
interface AttendanceRecorder
{
    /** Ouvre ou retrouve la séance, idempotente par `dp:<classe>:<demi-journée>` et `clientUuid`. */
    public function openDayPart(
        string $tenantId,
        Principal $principal,
        string $establishmentId,
        string $structureId,
        string $dayPart,
        string $date,
        string $clientUuid,
    ): AttendanceSession;

    /** Enregistre toute la classe : les personnes sans exception sont présentes par défaut. */
    /** @param list<AttendanceMark> $exceptions */
    public function record(
        string $tenantId,
        Principal $principal,
        string $establishmentId,
        string $sessionId,
        string $structureId,
        array $exceptions,
    ): AttendanceSession;

    /** Valide l’appel complet. */
    public function validate(string $tenantId, Principal $principal, string $establishmentId, string $sessionId): AttendanceSession;

    /** Corrige l’appel validé ; le lendemain, le droit attendance.correct_late et un motif sont requis. */
    /** @param list<AttendanceMark> $marks */
    public function correct(
        string $tenantId,
        Principal $principal,
        string $establishmentId,
        string $sessionId,
        array $marks,
        ?string $reason = null,
    ): AttendanceSession;
}
