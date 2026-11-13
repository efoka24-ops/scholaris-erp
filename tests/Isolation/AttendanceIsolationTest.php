<?php

declare(strict_types=1);

namespace Tests\Isolation;

use Scholaris\Modules\Socle\Scheduling\Contracts\SlotInfo;
use Tests\Support\Attendance\AttendanceTestCase;

/** Cloisonnement du module Attendance : par client (tenant) et par établissement. */
final class AttendanceIsolationTest extends AttendanceTestCase
{
    private string $sessionId;
    private string $justificationId;
    private string $incidentId;
    private string $sanctionId;
    private string $pickupId;

    protected function setUp(): void
    {
        $this->bootAttendance();
        [, $session] = $this->api('POST', '/attendance-sessions', 'teacher', ['client_uuid' => self::ulid(), 'slot_id' => 'slot1', 'date' => self::DAY, 'records' => $this->fullRecords(), 'validate' => true]);
        $this->sessionId = $session['data']['id'];
        $this->justificationId = $this->api('POST', '/absence-justifications', 'vs', ['person_id' => 's2', 'absence_from' => self::DAY, 'absence_to' => self::DAY, 'reason' => 'Maladie'])[1]['data']['id'];
        $this->incidentId = $this->api('POST', '/incidents', 'surveillant', ['person_id' => 's3', 'occurred_on' => self::DAY, 'category' => 'retard', 'description' => 'x'])[1]['data']['id'];
        $this->sanctionId = $this->api('POST', '/sanctions', 'surveillant', ['person_id' => 's3', 'kind' => 'blame', 'reason' => 'x'])[1]['data']['id'];
        $this->pickupId = $this->api('POST', '/authorized-pickups', 'surveillant', ['learner_id' => 's1', 'pickup_name' => 'Oncle', 'relationship' => 'oncle', 'grant_type' => 'permanent'])[1]['data']['id'];

        // Un utilisateur d'un autre client (t2), avec tous les droits.
        $this->access->grant('spy', 'x1');
        $this->access->grant('spy', 'e1');
        $this->permissions->grant('spy', 'attendance.read', 'attendance.take', 'attendance.justify', 'attendance.discipline.manage', 'attendance.discipline.approve');
        // Un utilisateur du même client, mais d'un autre établissement uniquement.
        $this->user('local-e2', 'p-local', ['attendance.read', 'attendance.take', 'attendance.justify', 'attendance.discipline.manage', 'attendance.discipline.approve']);
    }

    public function testUnAutreClientNeVoitRienEtNeModifieRien(): void
    {
        foreach ([
            '/attendance-sessions/' . $this->sessionId, '/absence-justifications/' . $this->justificationId, '/incidents/' . $this->incidentId, '/sanctions/' . $this->sanctionId,
        ] as $path) {
            [$response] = $this->api('GET', $path, 'spy', null, 't2', 'x1');
            self::assertSame(404, $response->getStatusCode(), $path);
        }
        foreach (['/attendance-sessions', '/attendance-records', '/absence-justifications', '/incidents', '/sanctions', '/authorized-pickups', '/attendance/not-allowed-to-sit'] as $path) {
            [, $json] = $this->api('GET', $path, 'spy', null, 't2', 'x1');
            self::assertSame([], $json['data'], $path);
        }

        $writes = [
            ['PUT', '/attendance-sessions/' . $this->sessionId . '/records', ['records' => [['person_id' => 's1', 'status' => 'A']]]],
            ['POST', '/attendance-sessions/' . $this->sessionId . '/validate', null],
            ['POST', '/attendance-sessions/' . $this->sessionId . '/qr-token', null],
            ['POST', '/absence-justifications/' . $this->justificationId . '/accept', null],
            ['POST', '/sanctions/' . $this->sanctionId . '/approve', null],
            ['POST', '/authorized-pickups/' . $this->pickupId . '/revoke', null],
        ];
        foreach ($writes as [$method, $path, $body]) {
            [$response] = $this->api($method, $path, 'spy', $body, 't2', 'x1');
            self::assertSame(404, $response->getStatusCode(), $method . ' ' . $path);
        }
        [$summary] = $this->api('GET', '/attendance/students/s2/summary', 'spy', null, 't2', 'x1');
        [$replay] = $this->api('POST', '/attendance-sessions', 'spy', ['client_uuid' => self::ulid(), 'slot_id' => 'slot1', 'date' => self::DAY], 't2', 'x1');
        [$person] = $this->api('POST', '/absence-justifications', 'spy', ['person_id' => 's2', 'absence_from' => self::DAY, 'absence_to' => self::DAY, 'reason' => 'x'], 't2', 'x1');

        self::assertContains($summary->getStatusCode(), [200, 404]);
        self::assertSame(404, $replay->getStatusCode(), 'Le créneau d\'un autre client est introuvable.');
        self::assertSame(404, $person->getStatusCode());
        self::assertSame('pending', $this->rows('SELECT status FROM absence_justifications')[0]['status']);
        self::assertSame('proposed', $this->rows('SELECT status FROM sanctions')[0]['status']);
        self::assertSame('active', $this->rows('SELECT status FROM authorized_pickups')[0]['status']);
        self::assertSame('validated', $this->rows('SELECT status FROM attendance_sessions')[0]['status']);
    }

    public function testLeResumeNeFuitPasVersUnAutreClient(): void
    {
        [, $json] = $this->api('GET', '/attendance/students/s2/summary', 'spy', null, 't2', 'x1');

        self::assertSame(0, $json['data']['sessions'] ?? 0);
    }

    public function testCloisonnementParEtablissementDansLeMemeClient(): void
    {
        foreach (['/attendance-sessions/' . $this->sessionId, '/absence-justifications/' . $this->justificationId, '/incidents/' . $this->incidentId, '/sanctions/' . $this->sanctionId] as $path) {
            [$response] = $this->api('GET', $path, 'local-e2', null, 't1', 'e2');
            self::assertSame(404, $response->getStatusCode(), $path);
        }
        foreach (['/attendance-sessions', '/attendance-records', '/absence-justifications', '/incidents', '/sanctions', '/authorized-pickups'] as $path) {
            [, $json] = $this->api('GET', $path, 'local-e2', null, 't1', 'e2');
            self::assertSame([], $json['data'], $path);
        }
        foreach ([['PUT', '/attendance-sessions/' . $this->sessionId . '/records', ['records' => [['person_id' => 's1', 'status' => 'A']]]], ['POST', '/sanctions/' . $this->sanctionId . '/approve', null], ['POST', '/absence-justifications/' . $this->justificationId . '/reject', null]] as [$method, $path, $body]) {
            [$response] = $this->api($method, $path, 'local-e2', $body, 't1', 'e2');
            self::assertSame(404, $response->getStatusCode(), $method . ' ' . $path);
        }
        [, $summary] = $this->api('GET', '/attendance/students/s2/summary', 'local-e2', null, 't1', 'e2');
        self::assertSame(0, $summary['data']['sessions']);
    }

    public function testUnCreneauDUnAutreEtablissementNEstPasUtilisable(): void
    {
        $this->timetable->add(new SlotInfo('slot-e2', 't1', 'e2', 'c1', 'p-teacher', 'Physique', 4, '10:00', '11:00', '2026-09-01'));

        [$response] = $this->api('POST', '/attendance-sessions', 'teacher', ['client_uuid' => self::ulid(), 'slot_id' => 'slot-e2', 'date' => self::DAY]);

        self::assertSame(404, $response->getStatusCode());
    }

    public function testUnUtilisateurSansDroitSurLEtablissementEstRefuse(): void
    {
        [$response] = $this->api('GET', '/attendance-sessions', 'reader', null, 't1', 'e9');

        self::assertSame(403, $response->getStatusCode());
    }
}
