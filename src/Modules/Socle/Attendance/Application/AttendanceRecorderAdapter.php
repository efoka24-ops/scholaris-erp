<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Attendance\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Socle\Attendance\Contracts\AttendanceMark;
use Scholaris\Modules\Socle\Attendance\Contracts\AttendanceRecorder;
use Scholaris\Modules\Socle\Attendance\Contracts\AttendanceSession;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;

/** Adaptateur du contrat inter-applications vers le service d’appel existant. */
final class AttendanceRecorderAdapter implements AttendanceRecorder
{
    public function __construct(
        private readonly SessionService $sessions,
        private readonly EnrollmentDirectory $enrollments,
    ) {
    }

    public function openDayPart(
        string $tenantId,
        Principal $principal,
        string $establishmentId,
        string $structureId,
        string $dayPart,
        string $date,
        string $clientUuid,
    ): AttendanceSession {
        $this->assertTenant($tenantId, $principal);
        try {
            [$session] = $this->sessions->openDayPart($principal, $establishmentId, $structureId, $dayPart, $date, $clientUuid);
        } catch (SessionConflict $conflict) {
            $server = $conflict->serverVersion;
            $key = 'dp:' . $structureId . ':' . $dayPart;
            if (($server['slot_id'] ?? null) !== $key || ($server['session_date'] ?? null) !== $date || !isset($server['id'])) {
                throw $conflict;
            }
            $session = $this->sessions->find($tenantId, $establishmentId, (string) $server['id']);
        }

        return self::value($session);
    }

    /** @param list<AttendanceMark> $exceptions */
    public function record(
        string $tenantId,
        Principal $principal,
        string $establishmentId,
        string $sessionId,
        string $structureId,
        array $exceptions,
    ): AttendanceSession {
        $this->assertTenant($tenantId, $principal);
        $session = $this->sessions->find($tenantId, $establishmentId, $sessionId);
        if (($session['structure_id'] ?? null) !== $structureId) {
            throw HttpException::notFound('Appel introuvable dans cette classe.');
        }

        $byPerson = [];
        foreach ($exceptions as $mark) {
            if (isset($byPerson[$mark->personId])) {
                throw HttpException::validation(['records' => ['Un élève ne peut apparaître qu’une fois dans les exceptions.']]);
            }
            $byPerson[$mark->personId] = $mark;
        }
        $lines = [];
        foreach ($this->enrollments->personIdsInStructure($tenantId, $structureId) as $personId) {
            $mark = $byPerson[$personId] ?? new AttendanceMark($personId);
            $lines[] = self::line($mark);
        }
        if (array_diff(array_keys($byPerson), array_column($lines, 'person_id')) !== []) {
            throw HttpException::validation(['records' => ['Une exception concerne une personne qui n’est pas inscrite dans cette classe.']]);
        }

        return self::value($this->sessions->saveRecords($principal, $establishmentId, $sessionId, $lines));
    }

    public function validate(string $tenantId, Principal $principal, string $establishmentId, string $sessionId): AttendanceSession
    {
        $this->assertTenant($tenantId, $principal);

        return self::value($this->sessions->validate($principal, $establishmentId, $sessionId));
    }

    /** @param list<AttendanceMark> $marks */
    public function correct(
        string $tenantId,
        Principal $principal,
        string $establishmentId,
        string $sessionId,
        array $marks,
        ?string $reason = null,
    ): AttendanceSession {
        $this->assertTenant($tenantId, $principal);
        $lines = [];
        foreach ($marks as $mark) {
            $lines[] = self::line($mark);
        }

        return self::value($this->sessions->correctRecords($principal, $establishmentId, $sessionId, $lines, $reason ?? ''));
    }

    /** @param array<string, mixed> $session */
    private static function value(array $session): AttendanceSession
    {
        $marks = [];
        foreach ($session['records'] ?? [] as $record) {
            $marks[] = new AttendanceMark(
                (string) $record['person_id'],
                (string) $record['status'],
                isset($record['arrived_at']) ? (string) $record['arrived_at'] : null,
                isset($record['client_uuid']) ? (string) $record['client_uuid'] : null,
                isset($record['note']) ? (string) $record['note'] : null,
            );
        }
        $slot = (string) ($session['slot_id'] ?? '');
        $parts = explode(':', $slot);

        return new AttendanceSession(
            (string) $session['id'],
            (string) $session['establishment_id'],
            (string) $session['structure_id'],
            (string) $session['session_date'],
            (string) ($parts[2] ?? ''),
            (string) $session['status'],
            $marks,
        );
    }

    /** @return array{person_id: string, status: string, arrived_at: ?string, client_uuid: ?string, note: ?string} */
    private static function line(AttendanceMark $mark): array
    {
        return [
            'person_id' => $mark->personId,
            'status' => $mark->status,
            'arrived_at' => $mark->arrivedAt,
            'client_uuid' => $mark->clientUuid,
            'note' => $mark->note,
        ];
    }

    private function assertTenant(string $tenantId, Principal $principal): void
    {
        if ($principal->tenantId !== $tenantId) {
            throw HttpException::notFound('Appel introuvable dans ce client.');
        }
    }
}
