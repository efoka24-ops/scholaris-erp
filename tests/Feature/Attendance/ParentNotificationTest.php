<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use Scholaris\Modules\Socle\Scheduling\Contracts\SlotInfo;
use Tests\Support\Attendance\AttendanceTestCase;

/** T112 : le garant est prévenu le jour même, à la validation, pour une absence non justifiée, une seule fois par élève et par jour. */
final class ParentNotificationTest extends AttendanceTestCase
{
    protected function setUp(): void
    {
        $this->bootAttendance();
        $this->timetable->add(new SlotInfo('slot2', 't1', 'e1', 'c1', 'p-teacher', 'Français', 4, '10:00', '11:55', '2026-09-01'));
    }

    /** @param list<array<string, mixed>> $records */
    private function validated(string $slot, array $records, string $date = self::DAY): void
    {
        [$response] = $this->api('POST', '/attendance-sessions', 'teacher', ['client_uuid' => self::ulid(), 'slot_id' => $slot, 'date' => $date, 'records' => $records, 'validate' => true]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
    }

    public function testLeParentDeLAbsentRecoitLeModeleAbsenceNotice(): void
    {
        $this->validated('slot1', $this->fullRecords());

        $sent = $this->messenger->of('absence_notice');
        self::assertCount(1, $sent);
        self::assertSame('p-parent2', $sent[0]['person']);
        self::assertSame('e1', $sent[0]['establishment']);
        self::assertSame(['child_name' => 'Nadia Foka', 'absence_date' => '2026-10-08', 'establishment_name' => 'IPES Douala'], $sent[0]['variables']);
        self::assertSame('sent', $this->rows('SELECT status FROM absence_notifications')[0]['status']);
    }

    public function testRienNEstEnvoyeAvantLaValidation(): void
    {
        $session = $this->openSession();
        $this->api('PUT', '/attendance-sessions/' . $session['id'] . '/records', 'teacher', ['records' => $this->fullRecords()]);

        self::assertSame([], $this->messenger->sent, 'Le parent n\'est prévenu qu\'à la synchronisation (validation).');

        $this->api('POST', '/attendance-sessions/' . $session['id'] . '/validate', 'teacher');
        self::assertCount(1, $this->messenger->sent);
    }

    public function testUnSeulMessageParEleveEtParJour(): void
    {
        $this->validated('slot1', $this->fullRecords());
        $this->validated('slot2', $this->fullRecords());

        self::assertCount(1, $this->messenger->of('absence_notice'));
        self::assertCount(1, $this->rows('SELECT id FROM absence_notifications'));
    }

    public function testLeLendemainUnNouveauMessagePeutPartir(): void
    {
        $this->validated('slot1', $this->fullRecords());
        $this->validated('slot1', $this->fullRecords(), '2026-10-01');

        self::assertCount(2, $this->messenger->of('absence_notice'));
    }

    public function testUneAbsenceJustifieeNeNotifiePas(): void
    {
        [$justification] = $this->api('POST', '/absence-justifications', 'vs', ['person_id' => 's2', 'absence_from' => self::DAY, 'absence_to' => self::DAY, 'reason' => 'Rendez-vous médical']);
        $id = $this->rows('SELECT id FROM absence_justifications')[0]['id'];
        $this->api('POST', '/absence-justifications/' . $id . '/accept', 'vs');
        self::assertSame(201, $justification->getStatusCode());

        $this->validated('slot1', $this->fullRecords());

        self::assertSame([], $this->messenger->sent);
        self::assertSame('1', (string) $this->rows("SELECT justified FROM attendance_records WHERE person_id = 's2'")[0]['justified'], 'Le justificatif accepté d\'avance couvre l\'absence.');
    }

    public function testPresentsEtRetardsNeNotifientPas(): void
    {
        $this->validated('slot1', $this->fullRecords('P', 'P', 'R'));

        self::assertSame([], $this->messenger->sent);
        self::assertSame([], $this->rows('SELECT id FROM absence_notifications'));
    }

    public function testLeParametreEtablissementPeutDesactiverLaNotification(): void
    {
        $this->settings->values['attendance.notify_parents'] = false;

        $this->validated('slot1', $this->fullRecords());

        self::assertSame([], $this->messenger->sent);
    }

    public function testUnePanneDeMessagerieNeBloquePasLaValidation(): void
    {
        $this->messenger->fail = true;

        $this->validated('slot1', $this->fullRecords());

        self::assertSame('validated', $this->rows('SELECT status FROM attendance_sessions')[0]['status']);
        self::assertSame('failed', $this->rows('SELECT status FROM absence_notifications')[0]['status']);
    }

    public function testSansGarantLAbsenceEstTraceeSansEnvoi(): void
    {
        $this->validated('slot1', $this->fullRecords('P', 'P', 'A'));

        self::assertSame([], $this->messenger->sent);
        self::assertSame('no_recipient', $this->rows("SELECT status FROM absence_notifications WHERE person_id = 's3'")[0]['status']);
    }
}
