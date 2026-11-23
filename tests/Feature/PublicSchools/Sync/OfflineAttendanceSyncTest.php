<?php

declare(strict_types=1);

namespace Tests\Feature\PublicSchools\Sync;

use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;
use Tests\Support\PublicSchools\PublicSchoolsTestCase;

/**
 * T635 : gestionnaire de lots `sync.handlers.public.attendance` (API-04).
 * TST-04 : 10 jours hors ligne puis synchronisation = 10 × 2 demi-journées, aucun doublon.
 * SC-06 : le lot d'une classe de 60 élèves sur une semaine pèse moins de 50 Ko compressé.
 */
final class OfflineAttendanceSyncTest extends PublicSchoolsTestCase
{
    /** @var array{id: string, key: string} */
    private array $device;

    protected function setUp(): void
    {
        $this->bootPublic();
        $this->device = $this->registerDevice();
    }

    /** @return list<string> dix jours consécutifs, du 29 septembre au 8 octobre 2026 */
    private static function tenDays(): array
    {
        $days = [];
        for ($i = 0; $i < 10; ++$i) {
            $days[] = (new \DateTimeImmutable('2026-09-29'))->modify('+' . $i . ' days')->format('Y-m-d');
        }

        return $days;
    }

    /** @return list<array{kind: string, client_uuid: string, payload: array<string, mixed>}> */
    private function twentyItems(): array
    {
        $items = [];
        foreach (self::tenDays() as $index => $day) {
            foreach (['morning', 'afternoon'] as $part) {
                $records = $index % 3 === 0 && $part === 'morning' ? [['person_id' => 's2', 'status' => 'A']] : [];
                $items[] = self::attendanceItem('c1', $day, $part, $records);
            }
        }

        return $items;
    }

    public function testDixJoursHorsLigneSynchronisesDonnentVingtDemiJourneesSansDoublon(): void
    {
        $items = $this->twentyItems();

        [$response, $json] = $this->postBatch($items, $this->device, 'teacher', 'LOT-SYN-TST04-01');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(['applied' => 20, 'duplicate' => 0, 'conflict' => 0, 'rejected' => 0, 'pending' => 0], $json['data']['counts']);
        self::assertCount(20, $this->rows('SELECT id FROM attendance_sessions'));
        self::assertCount(20, $this->rows('SELECT id FROM pub_attendance_calls'));
        self::assertCount(20, $this->rows("SELECT id FROM attendance_sessions WHERE status = 'validated'"));
        self::assertCount(20 * 3, $this->rows('SELECT id FROM attendance_records'), 'Tous présents par défaut : chaque élève a une ligne par appel.');
        self::assertCount(4, $this->rows("SELECT id FROM attendance_records WHERE status = 'A'"));

        // Reprise après une coupure : le même lot (même identifiant) puis les mêmes éléments dans un nouveau lot.
        [$replayed, $replayJson] = $this->postBatch($items, $this->device, 'teacher', 'LOT-SYN-TST04-01');
        self::assertSame(200, $replayed->getStatusCode(), (string) $replayed->getBody());
        self::assertTrue($replayJson['data']['replayed']);
        [$again, $againJson] = $this->postBatch($items, $this->device, 'teacher', 'LOT-SYN-TST04-02');
        self::assertSame(200, $again->getStatusCode(), (string) $again->getBody());
        self::assertSame(20, $againJson['data']['counts']['duplicate']);

        self::assertCount(20, $this->rows('SELECT id FROM attendance_sessions'), 'Aucun doublon après les rejeux.');
        self::assertCount(20, $this->rows('SELECT id FROM pub_attendance_calls'));
        self::assertCount(4, $this->messenger->of('absence_notice'), 'Une seule alerte à la famille par absence, jamais rejouée.');
    }

    public function testUnAppareilQuiRegenereSesIdentifiantsNeDupliquePas(): void
    {
        $this->postBatch([self::attendanceItem('c1', self::DAY, 'morning', [['person_id' => 's2', 'status' => 'A']])], $this->device);

        [, $json] = $this->postBatch([self::attendanceItem('c1', self::DAY, 'morning', [['person_id' => 's2', 'status' => 'A']])], $this->device);

        self::assertSame('duplicate', $json['data']['items'][0]['status']);
        self::assertCount(1, $this->rows('SELECT id FROM attendance_sessions'));
    }

    public function testUnAppelDejaValideEtDifferentEstRenduEnConflitSansEcrasement(): void
    {
        $this->putAttendance('teacher', 'c1', self::DAY, 'morning', [['person_id' => 's2', 'status' => 'A']]);

        [$response, $json] = $this->postBatch([self::attendanceItem('c1', self::DAY, 'morning', [['person_id' => 's3', 'status' => 'A']])], $this->device);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('conflict', $json['data']['items'][0]['status']);
        self::assertSame(409, $json['data']['items'][0]['code']);
        $statuses = array_column($this->rows('SELECT person_id, status FROM attendance_records'), 'status', 'person_id');
        self::assertSame(['s1' => 'P', 's2' => 'A', 's3' => 'P'], $statuses, 'La version du serveur n\'est jamais écrasée par la synchronisation.');
    }

    public function testChaqueElementEstControleIndependammentDansSonPerimetre(): void
    {
        $items = [
            self::attendanceItem('c1', self::DAY, 'morning'),
            self::attendanceItem('c2', self::DAY, 'morning'),
            self::attendanceItem('c1', self::DAY, 'night'),
            ['kind' => 'public.attendance', 'client_uuid' => (string) new Ulid(), 'payload' => ['date' => self::DAY]],
        ];

        [$response, $json] = $this->postBatch($items, $this->device, 'teacher');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(['applied', 'rejected', 'rejected', 'rejected'], array_column($json['data']['items'], 'status'));
        self::assertSame(403, $json['data']['items'][1]['code'], 'La classe d\'un collègue est hors périmètre de l\'enseignant.');
        self::assertSame(422, $json['data']['items'][2]['code']);
        self::assertCount(1, $this->rows('SELECT id FROM attendance_sessions'), 'Les autres éléments du lot ne sont pas perdus ni appliqués à tort.');
    }

    public function testUnClientSuspenduRefuseLesEcrituresHorsLigne(): void
    {
        $this->applications->set('t1', 'public_primary', ApplicationAccess::SUSPENDED);

        [, $json] = $this->postBatch([self::attendanceItem('c1', self::DAY, 'morning')], $this->device);

        self::assertSame('rejected', $json['data']['items'][0]['status']);
        self::assertSame(423, $json['data']['items'][0]['code']);
        self::assertSame([], $this->rows('SELECT id FROM attendance_sessions'));
    }

    public function testLeLotDUneSemaineDUneClasseDe60ElevesPeseMoinsDe50KoCompresse(): void
    {
        $pupils = [];
        for ($i = 0; $i < 60; ++$i) {
            $pupils[] = (string) new Ulid();
        }
        $typical = [];
        $worst = [];
        for ($d = 0; $d < 5; ++$d) {
            $day = (new \DateTimeImmutable('2026-10-05'))->modify('+' . $d . ' days')->format('Y-m-d');
            foreach (['morning', 'afternoon'] as $part) {
                // Cas courant : 6 élèves sur 60 en exception (10 %), dont une note.
                $few = [];
                foreach (array_slice($pupils, $d, 6) as $pupilId) {
                    $few[] = ['person_id' => $pupilId, 'status' => 'A', 'note' => 'Absence signalée par SMS'];
                }
                $typical[] = self::attendanceItem('01M5SYNCLASSE0000000000001', $day, $part, $few);
                // Pire cas : les 60 élèves sont des exceptions, avec heure d'arrivée et note.
                $all = [];
                foreach ($pupils as $pupilId) {
                    $all[] = ['person_id' => $pupilId, 'status' => 'R', 'arrived_at' => '08:15', 'note' => 'Retard transport SYN'];
                }
                $worst[] = self::attendanceItem('01M5SYNCLASSE0000000000001', $day, $part, $all);
            }
        }

        $typicalSize = strlen((string) gzencode(json_encode(['items' => $typical], JSON_THROW_ON_ERROR)));
        $worstSize = strlen((string) gzencode(json_encode(['items' => $worst], JSON_THROW_ON_ERROR)));

        self::assertLessThan(50 * 1024, $typicalSize, 'SC-06 : lot courant de ' . $typicalSize . ' octets.');
        self::assertLessThan(50 * 1024, $worstSize, 'SC-06 : pire cas de ' . $worstSize . ' octets.');
    }
}
