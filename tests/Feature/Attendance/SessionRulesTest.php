<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use Scholaris\Modules\Socle\Scheduling\Contracts\SlotInfo;
use Tests\Support\Attendance\AttendanceTestCase;

/** T111 : l'appel est rattaché à un créneau, fait par son enseignant (ou remplaçant), et ne se valide que complet. */
final class SessionRulesTest extends AttendanceTestCase
{
    protected function setUp(): void
    {
        $this->bootAttendance();
    }

    public function testLAppelRepriseLeCreneauEtLesEleves(): void
    {
        $session = $this->openSession();

        self::assertSame('slot1', $session['slot_id']);
        self::assertSame('c1', $session['structure_id']);
        self::assertSame('Mathématiques', $session['subject_label']);
        self::assertSame(['08:00', '09:55'], [$session['start_time'], $session['end_time']]);
        self::assertSame('open', $session['status']);
        self::assertArrayNotHasKey('tenant_id', $session);
        self::assertArrayNotHasKey('qr_token_hash', $session);
    }

    public function testUnCreneauInconnuOuDUnAutreEtablissementEstIntrouvable(): void
    {
        $this->timetable->add(new SlotInfo('slot-e2', 't1', 'e2', 'c9', 'p-teacher', 'Physique', 4, '10:00', '11:00', '2026-09-01'));

        [$unknown] = $this->api('POST', '/attendance-sessions', 'teacher', ['client_uuid' => self::ulid(), 'slot_id' => 'zzz', 'date' => self::DAY]);
        [$other] = $this->api('POST', '/attendance-sessions', 'teacher', ['client_uuid' => self::ulid(), 'slot_id' => 'slot-e2', 'date' => self::DAY]);

        self::assertSame(404, $unknown->getStatusCode());
        self::assertSame(404, $other->getStatusCode());
    }

    public function testSeulLEnseignantDuCreneauFaitLAppel(): void
    {
        [$response] = $this->api('POST', '/attendance-sessions', 'sub', ['client_uuid' => self::ulid(), 'slot_id' => 'slot1', 'date' => self::DAY]);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testUnRemplacantDesigneFaitLAppel(): void
    {
        $this->timetable->replace('slot1', self::DAY, 'p-sub');

        [$substitute] = $this->api('POST', '/attendance-sessions', 'sub', ['client_uuid' => self::ulid(), 'slot_id' => 'slot1', 'date' => self::DAY]);
        self::assertSame(201, $substitute->getStatusCode());
    }

    public function testUnCreneauAnnuleOuHorsJourNEstPasTenu(): void
    {
        $this->timetable->cancel('slot1', self::DAY);
        [$cancelled, $json] = $this->api('POST', '/attendance-sessions', 'teacher', ['client_uuid' => self::ulid(), 'slot_id' => 'slot1', 'date' => self::DAY]);
        [$wrongDay] = $this->api('POST', '/attendance-sessions', 'teacher', ['client_uuid' => self::ulid(), 'slot_id' => 'slot1', 'date' => '2026-10-07']);

        self::assertSame(422, $cancelled->getStatusCode());
        self::assertArrayHasKey('slot_id', $json['errors']);
        self::assertSame(422, $wrongDay->getStatusCode());
    }

    public function testOnNeFaitPasLAppelDUnJourAVenir(): void
    {
        [$response, $json] = $this->api('POST', '/attendance-sessions', 'teacher', ['client_uuid' => self::ulid(), 'slot_id' => 'slot1', 'date' => '2026-10-15']);

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('date', $json['errors']);
    }

    public function testLaValidationExigeUnStatutPourChaqueEleve(): void
    {
        $session = $this->openSession();
        $this->api('PUT', '/attendance-sessions/' . $session['id'] . '/records', 'teacher', ['records' => array_slice($this->fullRecords(), 0, 2)]);

        [$response, $json] = $this->api('POST', '/attendance-sessions/' . $session['id'] . '/validate', 'teacher');

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['s3'], $json['errors']['missing_person_ids']);
        self::assertStringContainsString('1 élève(s) sur 3', $json['errors']['records'][0]);
        self::assertSame('open', $this->rows('SELECT status FROM attendance_sessions')[0]['status']);
    }

    public function testLaValidationFigeLAppelEtDonneLesCompteurs(): void
    {
        $session = $this->openSession();
        $this->api('PUT', '/attendance-sessions/' . $session['id'] . '/records', 'teacher', ['records' => $this->fullRecords()]);

        [$response, $json] = $this->api('POST', '/attendance-sessions/' . $session['id'] . '/validate', 'teacher');
        [, $again] = $this->api('POST', '/attendance-sessions/' . $session['id'] . '/validate', 'teacher');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('validated', $json['data']['status']);
        self::assertSame([1, 1, 1, 3], [$json['data']['present_count'], $json['data']['absent_count'], $json['data']['late_count'], $json['data']['recorded_count']]);
        self::assertSame('teacher', $json['data']['validated_by']);
        self::assertSame($json['data']['validated_at'], $again['data']['validated_at']);
        self::assertCount(1, $this->rows("SELECT action FROM audit_log WHERE action = 'attendance.session.validated'"));
    }

    public function testUnRetardPrecisseLHeureEtLesAutresStatutsNon(): void
    {
        $session = $this->openSession();
        $path = '/attendance-sessions/' . $session['id'] . '/records';

        [$noTime, $a] = $this->api('PUT', $path, 'teacher', ['records' => [['person_id' => 's1', 'status' => 'R']]]);
        [$timeOnPresent, $b] = $this->api('PUT', $path, 'teacher', ['records' => [['person_id' => 's1', 'status' => 'P', 'arrived_at' => '08:10']]]);
        [$badStatus, $c] = $this->api('PUT', $path, 'teacher', ['records' => [['person_id' => 's1', 'status' => 'X']]]);
        [$badTime] = $this->api('PUT', $path, 'teacher', ['records' => [['person_id' => 's1', 'status' => 'R', 'arrived_at' => '25:99']]]);

        self::assertSame(422, $noTime->getStatusCode());
        self::assertStringContainsString('Ligne 1', $a['errors']['records'][0]);
        self::assertStringContainsString('heure d\'arrivée', $a['errors']['records'][0]);
        self::assertSame(422, $timeOnPresent->getStatusCode());
        self::assertStringContainsString('retard', $b['errors']['records'][0]);
        self::assertSame(422, $badStatus->getStatusCode());
        self::assertStringContainsString('P, A, R', $c['errors']['records'][0]);
        self::assertSame(422, $badTime->getStatusCode());
    }

    public function testUnEleveNonAttenduOuEnDoubleEstRefuse(): void
    {
        $session = $this->openSession();
        $this->people->add('t1', 'intrus', 'Intrus', 'X');

        [$stranger, $one] = $this->api('PUT', '/attendance-sessions/' . $session['id'] . '/records', 'teacher', ['records' => [['person_id' => 'intrus', 'status' => 'P']]]);
        [$twice, $two] = $this->api('PUT', '/attendance-sessions/' . $session['id'] . '/records', 'teacher', ['records' => [['person_id' => 's1', 'status' => 'P'], ['person_id' => 's1', 'status' => 'A']]]);

        self::assertSame(422, $stranger->getStatusCode());
        self::assertArrayHasKey('records.0.person_id', $one['errors']);
        self::assertSame(422, $twice->getStatusCode());
        self::assertArrayHasKey('records.1.person_id', $two['errors']);
        self::assertSame([], $this->rows('SELECT id FROM attendance_records'));
    }

    public function testUneSaisieSurUnAppelOuvertRemplaceLaPrecedente(): void
    {
        $session = $this->openSession();
        $path = '/attendance-sessions/' . $session['id'] . '/records';
        $this->api('PUT', $path, 'teacher', ['records' => [['person_id' => 's1', 'status' => 'A']]]);
        $this->api('PUT', $path, 'teacher', ['records' => [['person_id' => 's1', 'status' => 'R', 'arrived_at' => '08:30']]]);

        $rows = $this->rows("SELECT status, arrived_at FROM attendance_records WHERE person_id = 's1'");

        self::assertCount(1, $rows);
        self::assertSame(['R', '08:30'], [$rows[0]['status'], $rows[0]['arrived_at']]);
    }

    public function testLesDroitsSontRefusesParDefaut(): void
    {
        $session = $this->openSession();

        foreach ([['POST', '/attendance-sessions'], ['GET', '/attendance-sessions'], ['GET', '/attendance-sessions/' . $session['id']], ['POST', '/attendance-sessions/sync'], ['PUT', '/attendance-sessions/' . $session['id'] . '/records'], ['POST', '/attendance-sessions/' . $session['id'] . '/validate']] as [$method, $path]) {
            [$response] = $this->api($method, $path, 'nobody', in_array($method, ['POST', 'PUT'], true) ? ['sessions' => []] : null);
            self::assertSame(403, $response->getStatusCode(), $method . ' ' . $path);
        }
        // Un autre enseignant ne modifie pas l'appel d'autrui.
        [$other] = $this->api('POST', '/attendance-sessions/' . $session['id'] . '/validate', 'sub');
        self::assertSame(403, $other->getStatusCode());
        // La lecture est ouverte à la vie scolaire et aux lecteurs.
        [$read] = $this->api('GET', '/attendance-sessions/' . $session['id'], 'reader');
        self::assertSame(200, $read->getStatusCode());
    }

    public function testLaListeSeFiltre(): void
    {
        $this->openSession();

        [, $match] = $this->api('GET', '/attendance-sessions?filter[date]=' . self::DAY . '&filter[status]=open', 'reader');
        [, $none] = $this->api('GET', '/attendance-sessions?filter[status]=validated', 'reader');
        [$bad] = $this->api('GET', '/attendance-sessions?filter[date]=hier', 'reader');
        [$unknown] = $this->api('GET', '/attendance-sessions?filter[secret]=x', 'reader');

        self::assertCount(1, $match['data']);
        self::assertSame([], $none['data']);
        self::assertSame(422, $bad->getStatusCode());
        self::assertSame(422, $unknown->getStatusCode());
    }

    public function testLeCorpsInconnuOuInvalideEstRefuse(): void
    {
        [$unknown, $json] = $this->api('POST', '/attendance-sessions', 'teacher', ['client_uuid' => self::ulid(), 'slot_id' => 'slot1', 'date' => self::DAY, 'tenant_id' => 't2']);
        [$notUlid, $two] = $this->api('POST', '/attendance-sessions', 'teacher', ['client_uuid' => 'abc', 'slot_id' => 'slot1', 'date' => self::DAY]);

        self::assertSame(422, $unknown->getStatusCode());
        self::assertArrayHasKey('tenant_id', $json['errors']);
        self::assertSame(422, $notUlid->getStatusCode());
        self::assertArrayHasKey('client_uuid', $two['errors']);
    }

    public function testLePointageParCodeDeSeance(): void
    {
        $session = $this->openSession();
        $path = '/attendance-sessions/' . $session['id'];
        [$issued, $token] = $this->api('POST', $path . '/qr-token', 'teacher');
        $code = $token['data']['token'];

        self::assertSame(201, $issued->getStatusCode());
        self::assertSame('2026-10-08T09:10:00+01:00', $token['data']['expires_at']);
        self::assertNotContains($code, array_column($this->rows('SELECT qr_token_hash FROM attendance_sessions'), 'qr_token_hash'), 'Seule l\'empreinte est conservée.');

        [$wrong] = $this->api('POST', $path . '/scan', 'student1', ['token' => 'mauvais']);
        [$ok, $scan] = $this->api('POST', $path . '/scan', 'student1', ['token' => $code]);
        [$again] = $this->api('POST', $path . '/scan', 'student1', ['token' => $code]);
        $this->user('stranger', 'inconnu', []);
        [$notExpected] = $this->api('POST', $path . '/scan', 'stranger', ['token' => $code]);

        self::assertSame(403, $wrong->getStatusCode());
        self::assertSame(200, $ok->getStatusCode());
        self::assertSame(['s1', 'P'], [$scan['data']['person_id'], $scan['data']['status']]);
        self::assertSame(200, $again->getStatusCode());
        self::assertCount(1, $this->rows('SELECT id FROM attendance_records'));
        self::assertSame(403, $notExpected->getStatusCode());

        $this->clock->set('2026-10-08T09:11:00+01:00');
        $this->user('student2', 's2', []);
        [$expired] = $this->api('POST', $path . '/scan', 'student2', ['token' => $code]);
        self::assertSame(403, $expired->getStatusCode(), 'Le code expire après la plage courte.');
    }

    public function testLeCodeDeSeanceSeReglePar(): void
    {
        $this->bootAttendance(['attendance.qr_window_minutes' => 3]);
        $session = $this->openSession();

        [, $token] = $this->api('POST', '/attendance-sessions/' . $session['id'] . '/qr-token', 'teacher');

        self::assertSame('2026-10-08T09:03:00+01:00', $token['data']['expires_at']);
    }
}
