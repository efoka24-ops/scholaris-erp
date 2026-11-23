<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use Tests\Support\Attendance\AttendanceTestCase;

/** PP17 : mode `day_part` de l'appel (sans créneau, par classe et par moment de la journée) ; le mode `slot` reste inchangé. */
final class DayPartModeTest extends AttendanceTestCase
{
    /** @return array<string, mixed> */
    private function body(string $part = 'morning', string $structure = 'c1', ?string $uuid = null): array
    {
        return ['client_uuid' => $uuid ?? self::ulid(), 'structure_id' => $structure, 'day_part' => $part, 'date' => self::DAY];
    }

    public function testAppelSansCreneauEnModeDayPart(): void
    {
        $this->bootAttendance(['attendance.mode' => 'day_part']);

        [$response, $json] = $this->api('POST', '/attendance-sessions', 'teacher', $this->body('morning'));

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $session = $json['data'];
        self::assertSame('dp:c1:morning', $session['slot_id']);
        self::assertSame(['c1', 'p-teacher', 'open', 3], [$session['structure_id'], $session['teacher_person_id'], $session['status'], $session['expected_count']]);
        self::assertSame('Matin', $session['subject_label']);

        $this->api('PUT', '/attendance-sessions/' . $session['id'] . '/records', 'teacher', ['records' => $this->fullRecords()]);
        [$validated, $detail] = $this->api('POST', '/attendance-sessions/' . $session['id'] . '/validate', 'teacher');
        self::assertSame(200, $validated->getStatusCode());
        self::assertSame([1, 1, 1], [$detail['data']['present_count'], $detail['data']['absent_count'], $detail['data']['late_count']]);
        self::assertCount(1, $this->messenger->of('absence_notice'));
        self::assertSame([], $this->hours->lines, 'Aucune heure enseignée sans créneau.');
    }

    public function testMatinPuisApresMidiLeMemeJourEtTroisiemeMatinEnConflit(): void
    {
        $this->bootAttendance(['attendance.mode' => 'day_part']);

        [$morning] = $this->api('POST', '/attendance-sessions', 'teacher', $this->body('morning'));
        [$afternoon, $json] = $this->api('POST', '/attendance-sessions', 'teacher', $this->body('afternoon'));
        [$again, $conflict] = $this->api('POST', '/attendance-sessions', 'teacher', $this->body('morning'));

        self::assertSame([201, 201, 409], [$morning->getStatusCode(), $afternoon->getStatusCode(), $again->getStatusCode()]);
        self::assertSame('dp:c1:afternoon', $json['data']['slot_id']);
        self::assertSame('dp:c1:morning', $conflict['server_version']['slot_id']);
        self::assertSame('morning', $conflict['client_version']['day_part']);
        self::assertCount(2, $this->rows('SELECT id FROM attendance_sessions'));
    }

    public function testRejouParClientUuidEtSynchronisationHorsLigne(): void
    {
        $this->bootAttendance(['attendance.mode' => 'day_part']);
        $uuid = self::ulid();

        [$first] = $this->api('POST', '/attendance-sessions', 'teacher', $this->body('morning', 'c1', $uuid));
        [$second, $replayed] = $this->api('POST', '/attendance-sessions', 'teacher', $this->body('morning', 'c1', $uuid));
        self::assertSame([201, 200], [$first->getStatusCode(), $second->getStatusCode()]);
        self::assertTrue($replayed['meta']['replayed']);

        $item = $this->body('afternoon') + ['records' => $this->fullRecords(), 'validate' => true];
        [, $one] = $this->api('POST', '/attendance-sessions/sync', 'teacher', ['sessions' => [$item]]);
        [, $two] = $this->api('POST', '/attendance-sessions/sync', 'teacher', ['sessions' => [$item]]);
        self::assertSame(['synced', false], [$one['data'][0]['status'], $one['data'][0]['replayed']]);
        self::assertSame(['synced', true], [$two['data'][0]['status'], $two['data'][0]['replayed']]);
        self::assertSame('validated', $two['data'][0]['session']['status']);
        self::assertCount(2, $this->rows('SELECT id FROM attendance_sessions'));
    }

    public function testLeModeSlotRefuseLAppelParMomentDeLaJourneeEtResteInchange(): void
    {
        $this->bootAttendance();

        [$response, $json] = $this->api('POST', '/attendance-sessions', 'teacher', $this->body('morning'));
        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('day_part', $json['errors']);
        self::assertSame([], $this->rows('SELECT id FROM attendance_sessions'));

        self::assertSame('slot1', $this->openSession()['slot_id']);
    }

    public function testEnModeDayPartLeCreneauResteAccepte(): void
    {
        $this->bootAttendance(['attendance.mode' => 'day_part']);

        self::assertSame('slot1', $this->openSession()['slot_id']);
    }

    public function testValidationDeLaCible(): void
    {
        $this->bootAttendance(['attendance.mode' => 'day_part']);
        $uuid = self::ulid();
        $cases = [
            'les deux cibles' => [['client_uuid' => $uuid, 'slot_id' => 'slot1', 'structure_id' => 'c1', 'day_part' => 'morning', 'date' => self::DAY], 'day_part'],
            'sans cible' => [['client_uuid' => $uuid, 'date' => self::DAY], 'slot_id'],
            'sans classe' => [['client_uuid' => $uuid, 'day_part' => 'morning', 'date' => self::DAY], 'structure_id'],
            'classe seule' => [['client_uuid' => $uuid, 'structure_id' => 'c1', 'date' => self::DAY], 'day_part'],
            'moment inconnu' => [$this->body('night'), 'day_part'],
            'clé trop longue' => [$this->body('afternoon', str_repeat('c', 40)), 'structure_id'],
        ];

        foreach ($cases as $label => [$payload, $field]) {
            [$response, $json] = $this->api('POST', '/attendance-sessions', 'teacher', $payload);
            self::assertSame(422, $response->getStatusCode(), $label);
            self::assertArrayHasKey($field, $json['errors'], $label);
        }
        self::assertSame([], $this->rows('SELECT id FROM attendance_sessions'));
    }

    public function testClasseInconnueOuDUnAutreEtablissementEtDroits(): void
    {
        $this->bootAttendance(['attendance.mode' => 'day_part']);
        $this->enrollments->add('t1', 'en-x', 's-x', 'e2', 'c-e2');

        [$unknown] = $this->api('POST', '/attendance-sessions', 'teacher', $this->body('morning', 'zzz'));
        [$otherEstablishment] = $this->api('POST', '/attendance-sessions', 'teacher', $this->body('morning', 'c-e2'));
        [$future] = $this->api('POST', '/attendance-sessions', 'teacher', ['date' => '2026-10-15'] + $this->body('morning'));
        [$denied] = $this->api('POST', '/attendance-sessions', 'nobody', $this->body('morning'));

        self::assertSame([404, 404, 422, 403], [$unknown->getStatusCode(), $otherEstablishment->getStatusCode(), $future->getStatusCode(), $denied->getStatusCode()]);
        self::assertSame([], $this->rows('SELECT id FROM attendance_sessions'));
    }

    public function testUnAutreEnseignantNeModifiePasLAppel(): void
    {
        $this->bootAttendance(['attendance.mode' => 'day_part']);
        [, $json] = $this->api('POST', '/attendance-sessions', 'teacher', $this->body('morning'));

        [$other] = $this->api('POST', '/attendance-sessions/' . $json['data']['id'] . '/validate', 'sub');

        self::assertSame(403, $other->getStatusCode());
    }
}
