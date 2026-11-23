<?php

declare(strict_types=1);

namespace Tests\Support\Nursery;

use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Attendance\Contracts\AttendanceMark;
use Scholaris\Modules\Socle\Attendance\Contracts\AttendanceRecorder;
use Scholaris\Modules\Socle\Attendance\Contracts\AttendanceSession;
use Scholaris\Modules\Socle\Attendance\Contracts\DailyPresence;

/** Doublure de l'appel du socle : séances idempotentes par section, demi-journée et jour ; absents lus par DailyPresence. */
final class WorldAttendance implements AttendanceRecorder, DailyPresence
{
    /** @var array<string, array{id: string, est: string, structure: string, date: string, part: string, status: string, marks: list<AttendanceMark>}> */
    public array $sessions = [];

    public function openDayPart(string $tenantId, Principal $principal, string $establishmentId, string $structureId, string $dayPart, string $date, string $clientUuid): AttendanceSession
    {
        $key = $tenantId . '|' . $structureId . '|' . $dayPart . '|' . $date;
        $this->sessions[$key] ??= ['id' => (string) new Ulid(), 'est' => $establishmentId, 'structure' => $structureId, 'date' => $date, 'part' => $dayPart, 'status' => 'open', 'marks' => []];

        return $this->value($this->sessions[$key]);
    }

    public function record(string $tenantId, Principal $principal, string $establishmentId, string $sessionId, string $structureId, array $exceptions): AttendanceSession
    {
        foreach ($this->sessions as $key => $session) {
            if ($session['id'] === $sessionId) {
                $this->sessions[$key]['marks'] = $exceptions;

                return $this->value($this->sessions[$key]);
            }
        }
        throw new \RuntimeException('Séance inconnue.');
    }

    public function validate(string $tenantId, Principal $principal, string $establishmentId, string $sessionId): AttendanceSession
    {
        foreach ($this->sessions as $key => $session) {
            if ($session['id'] === $sessionId) {
                $this->sessions[$key]['status'] = 'validated';

                return $this->value($this->sessions[$key]);
            }
        }
        throw new \RuntimeException('Séance inconnue.');
    }

    public function correct(string $tenantId, Principal $principal, string $establishmentId, string $sessionId, array $marks, ?string $reason = null): AttendanceSession
    {
        return $this->record($tenantId, $principal, $establishmentId, $sessionId, '', $marks);
    }

    public function absentOn(string $tenantId, string $establishmentId, string $date, ?string $structureId = null, ?string $dayPart = null): array
    {
        $out = [];
        foreach ($this->sessions as $key => $session) {
            if (!str_starts_with($key, $tenantId . '|') || $session['date'] !== $date || $session['est'] !== $establishmentId || $session['status'] !== 'validated') {
                continue;
            }
            if (($structureId !== null && $session['structure'] !== $structureId) || ($dayPart !== null && $session['part'] !== $dayPart)) {
                continue;
            }
            foreach ($session['marks'] as $mark) {
                if ($mark->status === AttendanceMark::ABSENT) {
                    $out[] = $mark->personId;
                }
            }
        }
        sort($out);

        return array_values(array_unique($out));
    }

    /** @param array{id: string, est: string, structure: string, date: string, part: string, status: string, marks: list<AttendanceMark>} $session */
    private function value(array $session): AttendanceSession
    {
        return new AttendanceSession($session['id'], $session['est'], $session['structure'], $session['date'], $session['part'], $session['status'], $session['marks']);
    }
}
