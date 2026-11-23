<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use Scholaris\Modules\Socle\Attendance\Application\AbsenceNotifier;
use Scholaris\Modules\Socle\Attendance\Console\RetryAbsenceNotificationsCommand;
use Tests\Support\Attendance\AttendanceTestCase;

/** Heures réalisées signalées à Staff à la validation d'un appel ; rejeu des notifications d'absence en échec. */
final class TaughtHoursAndRetryTest extends AttendanceTestCase
{
    protected function setUp(): void
    {
        $this->bootAttendance();
        $this->staff->add('t1', 'p-teacher', 'staff-1');
    }

    private function validate(): void
    {
        [$response] = $this->api('POST', '/attendance-sessions', 'teacher', ['client_uuid' => self::ulid(), 'slot_id' => 'slot1', 'date' => self::DAY, 'records' => $this->fullRecords(), 'validate' => true]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
    }

    public function testLaValidationSignaleLaSeanceTenueAStaff(): void
    {
        $this->validate();

        self::assertCount(1, $this->hours->lines);
        $line = array_values($this->hours->lines)[0];
        self::assertSame(['tenant' => 't1', 'establishment' => 'e1', 'staff' => 'staff-1', 'slot' => 'slot1', 'date' => self::DAY, 'minutes' => 115], $line);
    }

    public function testRienNEstSignaleAvantLaValidation(): void
    {
        $session = $this->openSession();
        $this->api('PUT', '/attendance-sessions/' . $session['id'] . '/records', 'teacher', ['records' => $this->fullRecords()]);

        self::assertSame(0, $this->hours->calls);
    }

    public function testLeRejeuDeLaValidationNeCreePasDeDoublon(): void
    {
        $session = $this->openSession();
        $this->api('PUT', '/attendance-sessions/' . $session['id'] . '/records', 'teacher', ['records' => $this->fullRecords()]);
        $this->api('POST', '/attendance-sessions/' . $session['id'] . '/validate', 'teacher');
        $this->api('POST', '/attendance-sessions/' . $session['id'] . '/validate', 'teacher');

        self::assertCount(1, $this->hours->lines);
    }

    public function testUnEnseignantHorsPersonnelEstIgnore(): void
    {
        $other = new \Tests\Support\Attendance\InMemoryStaff();
        $this->staff = $other;
        $this->kernel->container()->set(\Scholaris\Modules\Socle\Staff\Contracts\StaffDirectory::class, $other);
        $this->validate();

        self::assertSame(0, $this->hours->calls);
    }

    public function testUnePanneDeStaffNeBloquePasLaValidation(): void
    {
        $this->hours->fail = true;
        $this->validate();

        self::assertSame('validated', $this->rows('SELECT status FROM attendance_sessions')[0]['status']);
    }

    public function testRejeuDUneNotificationEchouee(): void
    {
        $this->messenger->fail = true;
        $this->validate();
        self::assertSame('failed', $this->rows('SELECT status FROM absence_notifications')[0]['status']);

        $this->messenger->fail = false;
        $result = $this->container()->get(AbsenceNotifier::class)->retryFailed();

        self::assertSame(['retried' => 1, 'sent' => 1, 'still_failed' => 0], $result);
        self::assertSame('sent', $this->rows('SELECT status FROM absence_notifications')[0]['status']);
        self::assertCount(1, $this->messenger->of('absence_notice'));

        self::assertSame(['retried' => 0, 'sent' => 0, 'still_failed' => 0], $this->container()->get(AbsenceNotifier::class)->retryFailed(), 'Rien à rejouer deux fois.');
        self::assertCount(1, $this->messenger->of('absence_notice'));
    }

    public function testLeRejeuResteEnEchecTantQueLaPanneDure(): void
    {
        $this->messenger->fail = true;
        $this->validate();

        $result = $this->container()->get(AbsenceNotifier::class)->retryFailed('t1');

        self::assertSame(['retried' => 1, 'sent' => 0, 'still_failed' => 1], $result);
        self::assertSame('failed', $this->rows('SELECT status FROM absence_notifications')[0]['status']);
    }

    public function testLeRejeuSeLimiteAuClientDemande(): void
    {
        $this->messenger->fail = true;
        $this->validate();
        $this->messenger->fail = false;

        self::assertSame(0, $this->container()->get(AbsenceNotifier::class)->retryFailed('t2')['retried']);
        self::assertSame('failed', $this->rows('SELECT status FROM absence_notifications')[0]['status']);
    }

    public function testLaCommandeAfficheLeBilan(): void
    {
        $this->messenger->fail = true;
        $this->validate();
        $this->messenger->fail = false;

        ob_start();
        $code = $this->container()->get(RetryAbsenceNotificationsCommand::class)->run([]);
        $output = (string) ob_get_clean();

        self::assertSame(0, $code);
        self::assertStringContainsString('rejouées : 1 (1 envoyée(s), 0 encore en échec)', $output);
    }
}
