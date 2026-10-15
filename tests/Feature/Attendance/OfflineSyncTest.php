<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use Tests\Support\Attendance\AttendanceTestCase;

/** T108 : l'appel enregistré hors ligne est rejoué sans doublon ; un conflit est signalé en 409 avec les deux versions. */
final class OfflineSyncTest extends AttendanceTestCase
{
    protected function setUp(): void
    {
        $this->bootAttendance();
    }

    public function testLeRejouDeLaCreationNeCreePasDeDoublon(): void
    {
        $uuid = self::ulid();
        $body = ['client_uuid' => $uuid, 'slot_id' => 'slot1', 'date' => self::DAY];

        [$first, $created] = $this->api('POST', '/attendance-sessions', 'teacher', $body);
        [$second, $replayed] = $this->api('POST', '/attendance-sessions', 'teacher', $body);

        self::assertSame(201, $first->getStatusCode());
        self::assertSame(200, $second->getStatusCode());
        self::assertFalse($created['meta']['replayed']);
        self::assertTrue($replayed['meta']['replayed']);
        self::assertSame($created['data']['id'], $replayed['data']['id']);
        self::assertCount(1, $this->rows('SELECT id FROM attendance_sessions'));
        self::assertSame(3, $created['data']['expected_count']);
    }

    public function testLeRejouDesLignesParLotNeCreePasDeDoublon(): void
    {
        $session = $this->openSession();
        $records = $this->fullRecords();

        [$first] = $this->api('PUT', '/attendance-sessions/' . $session['id'] . '/records', 'teacher', ['records' => $records]);
        [$second, $json] = $this->api('PUT', '/attendance-sessions/' . $session['id'] . '/records', 'teacher', ['records' => $records]);

        self::assertSame(200, $first->getStatusCode());
        self::assertSame(200, $second->getStatusCode());
        self::assertCount(3, $this->rows('SELECT id FROM attendance_records'));
        self::assertSame([1, 1, 1], [$json['data']['present_count'], $json['data']['absent_count'], $json['data']['late_count']]);
    }

    public function testLaSynchronisationParLotEstIdempotente(): void
    {
        $item = ['client_uuid' => self::ulid(), 'slot_id' => 'slot1', 'date' => self::DAY, 'records' => $this->fullRecords(), 'validate' => true];

        [$first, $one] = $this->api('POST', '/attendance-sessions/sync', 'teacher', ['sessions' => [$item]]);
        [$second, $two] = $this->api('POST', '/attendance-sessions/sync', 'teacher', ['sessions' => [$item]]);

        self::assertSame(200, $first->getStatusCode());
        self::assertSame('synced', $one['data'][0]['status']);
        self::assertFalse($one['data'][0]['replayed']);
        self::assertSame('synced', $two['data'][0]['status']);
        self::assertTrue($two['data'][0]['replayed']);
        self::assertSame('validated', $two['data'][0]['session']['status']);
        self::assertCount(1, $this->rows('SELECT id FROM attendance_sessions'));
        self::assertCount(3, $this->rows('SELECT id FROM attendance_records'));
        self::assertCount(1, $this->messenger->of('absence_notice'), 'Le rejeu ne prévient pas deux fois les parents.');
        self::assertNotNull($two['data'][0]['session']['synced_at']);
    }

    public function testUnAppelValideRejoueAvecUnContenuDifferentEstUnConflit(): void
    {
        $uuid = self::ulid();
        $item = ['client_uuid' => $uuid, 'slot_id' => 'slot1', 'date' => self::DAY, 'records' => $this->fullRecords(), 'validate' => true];
        $this->api('POST', '/attendance-sessions', 'teacher', $item);
        $session = $this->rows('SELECT id FROM attendance_sessions')[0]['id'];

        [$response, $json] = $this->api('PUT', '/attendance-sessions/' . $session . '/records', 'teacher', ['records' => [
            ['person_id' => 's2', 'status' => 'P', 'client_uuid' => self::ulid()],
        ]]);

        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('application/problem+json', $response->getHeaderLine('Content-Type'));
        self::assertSame('validated', $json['server_version']['status']);
        self::assertSame('A', array_values(array_filter($json['server_version']['records'], static fn (array $r): bool => $r['person_id'] === 's2'))[0]['status']);
        self::assertSame('P', $json['client_version']['records'][0]['status']);
        self::assertSame('A', $this->rows("SELECT status FROM attendance_records WHERE person_id = 's2'")[0]['status'], 'Rien n\'est résolu automatiquement.');
    }

    public function testUnRejouIdentiqueSurUnAppelValideEstSansEffet(): void
    {
        $session = $this->openSession();
        $records = $this->fullRecords();
        $this->api('PUT', '/attendance-sessions/' . $session['id'] . '/records', 'teacher', ['records' => $records]);
        $this->api('POST', '/attendance-sessions/' . $session['id'] . '/validate', 'teacher');

        [$response, $json] = $this->api('PUT', '/attendance-sessions/' . $session['id'] . '/records', 'teacher', ['records' => $records]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('validated', $json['data']['status']);
    }

    public function testUnAutreAppareilSurLeMemeCreneauEstEnConflit(): void
    {
        $first = $this->openSession();

        [$response, $json] = $this->api('POST', '/attendance-sessions', 'teacher', ['client_uuid' => self::ulid(), 'slot_id' => 'slot1', 'date' => self::DAY]);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame($first['id'], $json['server_version']['id']);
        self::assertSame('slot1', $json['client_version']['slot_id']);
    }

    public function testUnClientUuidReutilisePourUnAutreAppelEstUnConflit(): void
    {
        $uuid = self::ulid();
        $this->openSession('teacher', $uuid);

        [$response] = $this->api('POST', '/attendance-sessions', 'teacher', ['client_uuid' => $uuid, 'slot_id' => 'slot1', 'date' => '2026-10-01']);

        self::assertSame(409, $response->getStatusCode());
    }

    public function testLaSynchronisationIsoleLesErreursParAppel(): void
    {
        $good = ['client_uuid' => self::ulid(), 'slot_id' => 'slot1', 'date' => self::DAY];
        $badSlot = ['client_uuid' => self::ulid(), 'slot_id' => 'inconnu', 'date' => self::DAY];
        $future = ['client_uuid' => self::ulid(), 'slot_id' => 'slot1', 'date' => '2026-12-31'];

        [, $json] = $this->api('POST', '/attendance-sessions/sync', 'teacher', ['sessions' => [$badSlot, $good, $future]]);

        self::assertSame(['error', 'synced', 'error'], array_column($json['data'], 'status'));
        self::assertSame(404, $json['data'][0]['problem']['status']);
        self::assertSame(422, $json['data'][2]['problem']['status']);
    }

    public function testUnIdentifiantDeLigneNeServantQuUneFoisParAppel(): void
    {
        $session = $this->openSession();
        $shared = self::ulid();

        $this->api('PUT', '/attendance-sessions/' . $session['id'] . '/records', 'teacher', ['records' => [['person_id' => 's1', 'status' => 'P', 'client_uuid' => $shared]]]);
        [$response, $json] = $this->api('PUT', '/attendance-sessions/' . $session['id'] . '/records', 'teacher', ['records' => [['person_id' => 's2', 'status' => 'P', 'client_uuid' => $shared]]]);

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('records.0.client_uuid', $json['errors']);
    }
}
