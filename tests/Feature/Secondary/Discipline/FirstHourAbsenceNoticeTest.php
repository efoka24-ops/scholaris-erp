<?php

declare(strict_types=1);

namespace Tests\Feature\Secondary\Discipline;

use Scholaris\Modules\Socle\Scheduling\Contracts\SlotInfo;
use Tests\Support\Attendance\AttendanceTestCase;

/**
 * T884 : vérification de l'AbsenceNotifier du socle pour l'appel par heure du secondaire (mode `slot`, D13). Trois heures
 * d'absence non justifiée le même jour : un seul SMS au garant, parti à la validation de la PREMIÈRE heure. Comportement du socle
 * constaté, sans modification.
 */
final class FirstHourAbsenceNoticeTest extends AttendanceTestCase
{
    protected function setUp(): void
    {
        $this->bootAttendance();
        $this->timetable->add(new SlotInfo('slot-h2', 't1', 'e1', 'c1', 'p-teacher', 'Français', 4, '10:00', '10:55', '2026-09-01'));
        $this->timetable->add(new SlotInfo('slot-h3', 't1', 'e1', 'c1', 'p-teacher', 'Histoire', 4, '11:00', '11:55', '2026-09-01'));
    }

    private function validated(string $slot, string $date = self::DAY): void
    {
        [$response] = $this->api('POST', '/attendance-sessions', 'teacher', ['client_uuid' => self::ulid(), 'slot_id' => $slot, 'date' => $date, 'records' => $this->fullRecords(), 'validate' => true]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
    }

    public function testTroisHeuresDAbsenceLeMemeJourDonnentUnSeulSms(): void
    {
        $this->validated('slot1');
        self::assertCount(1, $this->messenger->of('absence_notice'), 'Le garant est prévenu dès la première heure.');
        $this->validated('slot-h2');
        $this->validated('slot-h3');

        self::assertCount(1, $this->messenger->of('absence_notice'));
        self::assertCount(1, $this->rows('SELECT id FROM absence_notifications'));
        self::assertSame('p-parent2', $this->messenger->of('absence_notice')[0]['person']);
    }
}
