<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use InvalidArgumentException;
use Scholaris\Modules\Socle\Attendance\Contracts\DailyPresence;
use Scholaris\Modules\Socle\Attendance\Infrastructure\PdoDailyPresence;
use Scholaris\Modules\Socle\Messaging\Contracts\AbsenteeDirectory;
use Scholaris\Modules\Socle\Scheduling\Contracts\SlotInfo;
use Tests\Support\Attendance\AttendanceTestCase;

/** PM8 (T703) : absents du jour par structure et par demi-journée, en mode `slot` comme en mode `day_part`. Données SYN-* uniquement. */
final class DailyPresenceTest extends AttendanceTestCase
{
    /** @param array<string, mixed> $settings */
    private function boot(array $settings = []): void
    {
        $this->bootAttendance($settings);
        $this->timetable->add(new SlotInfo('slot2', 't1', 'e1', 'c1', 'p-teacher', 'Sciences', 4, '14:00', '15:55', '2026-09-01', null, 'B12'));
        foreach (['s4' => 'Lea', 's5' => 'Omar'] as $id => $first) {
            $this->people->add('t1', $id, $first, 'SYN-Foka');
            $this->enrollments->add('t1', 'en-' . $id, $id, 'e1', 'c2');
        }
    }

    private function presence(): DailyPresence
    {
        return $this->container()->get(DailyPresence::class);
    }

    /**
     * Appel validé d'une classe pour un moment (mode day_part) ; `$statuses` = statut par élève.
     *
     * @param array<string, string> $statuses
     */
    private function dayPartCall(string $structure, string $part, array $statuses, bool $validate = true, string $date = self::DAY): string
    {
        [$created, $json] = $this->api('POST', '/attendance-sessions', 'teacher', ['client_uuid' => self::ulid(), 'structure_id' => $structure, 'day_part' => $part, 'date' => $date]);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());

        return $this->finish($json['data']['id'], $statuses, $validate);
    }

    /** @param array<string, string> $statuses */
    private function slotCall(string $slot, array $statuses, bool $validate = true): string
    {
        [$created, $json] = $this->api('POST', '/attendance-sessions', 'teacher', ['client_uuid' => self::ulid(), 'slot_id' => $slot, 'date' => self::DAY]);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());

        return $this->finish($json['data']['id'], $statuses, $validate);
    }

    /** @param array<string, string> $statuses */
    private function finish(string $sessionId, array $statuses, bool $validate): string
    {
        $records = [];
        foreach ($statuses as $person => $status) {
            $records[] = ['person_id' => $person, 'status' => $status, 'client_uuid' => self::ulid()] + ($status === 'R' ? ['arrived_at' => '08:12'] : []);
        }
        [$saved] = $this->api('PUT', '/attendance-sessions/' . $sessionId . '/records', 'teacher', ['records' => $records]);
        self::assertSame(200, $saved->getStatusCode(), (string) $saved->getBody());
        if ($validate) {
            [$validated] = $this->api('POST', '/attendance-sessions/' . $sessionId . '/validate', 'teacher');
            self::assertSame(200, $validated->getStatusCode(), (string) $validated->getBody());
        }

        return $sessionId;
    }

    public function testLeContratEstLie(): void
    {
        $this->boot();

        self::assertInstanceOf(PdoDailyPresence::class, $this->presence());
    }

    public function testModeDayPartAbsentsParDemiJournee(): void
    {
        $this->boot(['attendance.mode' => 'day_part']);
        $this->dayPartCall('c1', 'morning', ['s1' => 'P', 's2' => 'A', 's3' => 'R']);
        $this->dayPartCall('c1', 'afternoon', ['s1' => 'P', 's2' => 'P', 's3' => 'A']);

        self::assertSame(['s2'], $this->presence()->absentOn('t1', 'e1', self::DAY, null, DailyPresence::MORNING));
        self::assertSame(['s3'], $this->presence()->absentOn('t1', 'e1', self::DAY, null, DailyPresence::AFTERNOON));
        self::assertSame(['s2', 's3'], $this->presence()->absentOn('t1', 'e1', self::DAY), 'sans demi-journée : absent à au moins un appel du jour');
    }

    public function testUneSeanceJourneeCompleteCompteDansLesDeuxDemiJournees(): void
    {
        $this->boot(['attendance.mode' => 'day_part']);
        $this->dayPartCall('c2', 'full_day', ['s4' => 'A', 's5' => 'P']);

        self::assertSame(['s4'], $this->presence()->absentOn('t1', 'e1', self::DAY, 'c2', DailyPresence::MORNING));
        self::assertSame(['s4'], $this->presence()->absentOn('t1', 'e1', self::DAY, 'c2', DailyPresence::AFTERNOON));
    }

    public function testLaStructureEstFiltree(): void
    {
        $this->boot(['attendance.mode' => 'day_part']);
        $this->dayPartCall('c1', 'morning', ['s1' => 'A', 's2' => 'P', 's3' => 'P']);
        $this->dayPartCall('c2', 'morning', ['s4' => 'P', 's5' => 'A']);

        self::assertSame(['s1'], $this->presence()->absentOn('t1', 'e1', self::DAY, 'c1', DailyPresence::MORNING));
        self::assertSame(['s5'], $this->presence()->absentOn('t1', 'e1', self::DAY, 'c2', DailyPresence::MORNING));
        self::assertSame(['s1', 's5'], $this->presence()->absentOn('t1', 'e1', self::DAY, null, DailyPresence::MORNING), 'sans structure : toutes les classes de l\'établissement');
        self::assertSame([], $this->presence()->absentOn('t1', 'e1', self::DAY, 'c-inconnue'));
    }

    public function testModeSlotLaDemiJourneeVientDeLHeureDuCreneau(): void
    {
        $this->boot();
        $this->slotCall('slot1', ['s1' => 'P', 's2' => 'A', 's3' => 'P']);
        $this->slotCall('slot2', ['s1' => 'P', 's2' => 'P', 's3' => 'A']);

        self::assertSame(['s2'], $this->presence()->absentOn('t1', 'e1', self::DAY, 'c1', DailyPresence::MORNING));
        self::assertSame(['s3'], $this->presence()->absentOn('t1', 'e1', self::DAY, 'c1', DailyPresence::AFTERNOON));
        self::assertSame(['s2', 's3'], $this->presence()->absentOn('t1', 'e1', self::DAY, 'c1'));
    }

    public function testLHeureDeDebutDeLApresMidiEstUnParametreAConfirmer(): void
    {
        $this->boot(['attendance.afternoon_starts_at' => '08:00']);
        $this->slotCall('slot1', ['s1' => 'P', 's2' => 'A', 's3' => 'P']);

        self::assertSame([], $this->presence()->absentOn('t1', 'e1', self::DAY, 'c1', DailyPresence::MORNING), 'créneau de 08:00 : après-midi si l\'après-midi débute à 08:00');
        self::assertSame(['s2'], $this->presence()->absentOn('t1', 'e1', self::DAY, 'c1', DailyPresence::AFTERNOON));

        $this->settings->values['attendance.afternoon_starts_at'] = 'n\'importe quoi';
        self::assertSame(['s2'], $this->presence()->absentOn('t1', 'e1', self::DAY, 'c1', DailyPresence::MORNING), 'valeur invalide : défaut 12:00');
    }

    public function testLesDeuxModesCoexistentSurLaMemeJournee(): void
    {
        $this->boot(['attendance.mode' => 'day_part']);
        $this->dayPartCall('c2', 'morning', ['s4' => 'A', 's5' => 'P']);
        $this->slotCall('slot2', ['s1' => 'P', 's2' => 'A', 's3' => 'P']);
        $this->settings->values['attendance.mode'] = 'slot';

        self::assertSame(['s4'], $this->presence()->absentOn('t1', 'e1', self::DAY, null, DailyPresence::MORNING), 'la lecture suit la séance, pas le mode courant');
        self::assertSame(['s2'], $this->presence()->absentOn('t1', 'e1', self::DAY, null, DailyPresence::AFTERNOON));
        self::assertSame(['s2', 's4'], $this->presence()->absentOn('t1', 'e1', self::DAY));
    }

    public function testSeulsLesAbsentsDesAppelsValidesSontRetenus(): void
    {
        $this->boot(['attendance.mode' => 'day_part']);
        $this->dayPartCall('c1', 'morning', ['s1' => 'A', 's2' => 'R', 's3' => 'P'], false);
        $this->dayPartCall('c2', 'morning', ['s4' => 'R', 's5' => 'P']);

        self::assertSame([], $this->presence()->absentOn('t1', 'e1', self::DAY), 'appel non validé ignoré ; retard et présent ne sont pas absents');
    }

    public function testIsolationParClientEtParEtablissementEtParJour(): void
    {
        $this->boot(['attendance.mode' => 'day_part']);
        $this->dayPartCall('c1', 'morning', ['s1' => 'A', 's2' => 'P', 's3' => 'P']);

        self::assertSame(['s1'], $this->presence()->absentOn('t1', 'e1', self::DAY));
        self::assertSame([], $this->presence()->absentOn('t2', 'e1', self::DAY), 'autre client');
        self::assertSame([], $this->presence()->absentOn('t1', 'e2', self::DAY), 'autre établissement');
        self::assertSame([], $this->presence()->absentOn('t2', 'x1', self::DAY), 'client et établissement étrangers');
        self::assertSame([], $this->presence()->absentOn('t1', 'e1', '2026-10-07'), 'autre jour');
    }

    public function testUneDemiJourneeInconnueEstRefusee(): void
    {
        $this->boot();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Demi-journée inconnue');
        $this->presence()->absentOn('t1', 'e1', self::DAY, null, 'night');
    }

    public function testLeContratMessagingExistantResteInchange(): void
    {
        $this->boot(['attendance.mode' => 'day_part']);
        $this->dayPartCall('c1', 'morning', ['s1' => 'A', 's2' => 'P', 's3' => 'P']);
        $this->dayPartCall('c1', 'afternoon', ['s1' => 'P', 's2' => 'A', 's3' => 'P']);

        self::assertSame(['s1', 's2'], $this->container()->get(AbsenteeDirectory::class)->absentLearnerIds('t1', 'e1', self::DAY));
    }
}
