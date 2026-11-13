<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Attendance\Contracts\AttendanceMark;
use Scholaris\Modules\Socle\Attendance\Contracts\AttendanceRecorder;
use Tests\Support\Attendance\AttendanceTestCase;

final class AttendanceRecorderTest extends AttendanceTestCase
{
    public function testOuvreEtRetrouveUneSeanceDeFaconIdempotente(): void
    {
        $this->bootAttendance(['attendance.mode' => 'day_part']);
        $recorder = $this->container()->get(AttendanceRecorder::class);
        $principal = new Principal('teacher', 't1');
        $uuid = (string) new Ulid();

        $first = $recorder->openDayPart('t1', $principal, 'e1', 'c1', 'morning', self::DAY, $uuid);
        $again = $recorder->openDayPart('t1', $principal, 'e1', 'c1', 'morning', self::DAY, $uuid);

        self::assertSame($first->id, $again->id);
        self::assertSame('morning', $again->dayPart);
    }

    public function testDixJoursEtDeuxDemiJourneesNeCreentPasDeDoublons(): void
    {
        $this->bootAttendance(['attendance.mode' => 'day_part']);
        $recorder = $this->container()->get(AttendanceRecorder::class);
        $principal = new Principal('teacher', 't1');

        for ($day = 1; $day <= 10; ++$day) {
            $date = sprintf('2026-10-%02d', $day);
            $this->clock->set($date . 'T09:00:00+01:00');
            foreach (['morning', 'afternoon'] as $part) {
                $recorder->openDayPart('t1', $principal, 'e1', 'c1', $part, $date, (string) new Ulid());
            }
        }

        self::assertCount(20, $this->rows('SELECT id FROM attendance_sessions'));
    }

    public function testTousPresentsParDefautEtValidationDesExceptions(): void
    {
        $this->bootAttendance(['attendance.mode' => 'day_part']);
        $recorder = $this->container()->get(AttendanceRecorder::class);
        $principal = new Principal('teacher', 't1');
        $session = $recorder->openDayPart('t1', $principal, 'e1', 'c1', 'morning', self::DAY, (string) new Ulid());
        $recorded = $recorder->record('t1', $principal, 'e1', $session->id, 'c1', [new AttendanceMark('s2', AttendanceMark::ABSENT)]);
        $validated = $recorder->validate('t1', $principal, 'e1', $session->id);

        $statuses = [];
        foreach ($recorded->marks as $mark) {
            $statuses[$mark->personId] = $mark->status;
        }
        self::assertSame(['s1' => 'P', 's2' => 'A', 's3' => 'P'], $statuses);
        self::assertSame('validated', $validated->status);
    }

    public function testCorrectionAuLendemainDemandePermissionEtMotif(): void
    {
        $this->bootAttendance(['attendance.mode' => 'day_part']);
        $recorder = $this->container()->get(AttendanceRecorder::class);
        $principal = new Principal('teacher', 't1');
        $session = $recorder->openDayPart('t1', $principal, 'e1', 'c1', 'morning', self::DAY, (string) new Ulid());
        $marks = array_map(static fn (string $person): AttendanceMark => new AttendanceMark($person), ['s1', 's2', 's3']);
        $recorder->record('t1', $principal, 'e1', $session->id, 'c1', []);
        $recorder->validate('t1', $principal, 'e1', $session->id);
        $this->clock->set('2026-10-09T09:00:00+01:00');

        try {
            $recorder->correct('t1', $principal, 'e1', $session->id, $marks, 'Rectification SYN');
            self::fail('La correction du lendemain doit être refusée sans permission.');
        } catch (HttpException $exception) {
            self::assertSame(403, $exception->status);
        }

        $this->permissions->grant('teacher', 'attendance.correct_late');
        self::assertSame('validated', $recorder->correct('t1', $principal, 'e1', $session->id, $marks, 'Rectification SYN')->status);
    }

    public function testUnAutreClientOuEtablissementNePeutPasLireLaSeance(): void
    {
        $this->bootAttendance(['attendance.mode' => 'day_part']);
        $recorder = $this->container()->get(AttendanceRecorder::class);
        $principal = new Principal('teacher', 't1');
        $session = $recorder->openDayPart('t1', $principal, 'e1', 'c1', 'morning', self::DAY, (string) new Ulid());

        try {
            $recorder->validate('t2', new Principal('other', 't2'), 'e1', $session->id);
            self::fail('Une autre organisation doit obtenir un 404.');
        } catch (HttpException $exception) {
            self::assertSame(404, $exception->status);
        }
        try {
            $recorder->validate('t1', $principal, 'e2', $session->id);
            self::fail('Un autre établissement doit obtenir un 404.');
        } catch (HttpException $exception) {
            self::assertSame(404, $exception->status);
        }
    }
}
