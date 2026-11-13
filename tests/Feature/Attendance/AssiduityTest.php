<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use Scholaris\Modules\Socle\Attendance\Contracts\AttendanceSummaryProvider;
use Scholaris\Modules\Socle\Messaging\Contracts\AbsenteeDirectory;
use Tests\Support\Attendance\AttendanceTestCase;

/**
 * IPES EX-PRE-01 à 05, RG-PRE-1 et 2 : taux par élément, alerte à 25 %, non autorisé à composer au-dessus de 30 %.
 * Six jeudis d'appel (3, 10, 17, 24 septembre, 1er et 8 octobre 2026) : s2 absent les 3 et 10 (33,3 %), s3 absent le 24 (16,7 %).
 */
final class AssiduityTest extends AttendanceTestCase
{
    private const THURSDAYS = ['2026-09-03', '2026-09-10', '2026-09-17', '2026-09-24', '2026-10-01', '2026-10-08'];

    protected function setUp(): void
    {
        $this->bootAttendance();
        $this->callAll();
    }

    private function callAll(): void
    {
        $absent = ['s2' => ['2026-09-03', '2026-09-10'], 's3' => ['2026-09-24']];
        foreach (self::THURSDAYS as $day) {
            $records = [];
            foreach (['s1', 's2', 's3'] as $learner) {
                $records[] = ['person_id' => $learner, 'status' => in_array($day, $absent[$learner] ?? [], true) ? 'A' : 'P'];
            }
            [$response] = $this->api('POST', '/attendance-sessions', 'teacher', ['client_uuid' => self::ulid(), 'slot_id' => 'slot1', 'date' => $day, 'records' => $records, 'validate' => true]);
            self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        }
    }

    public function testLeResumeDonneLesCumulsLeTauxEtLeNiveau(): void
    {
        [, $json] = $this->api('GET', '/attendance/students/s2/summary', 'reader');

        self::assertSame(6, $json['data']['sessions']);
        self::assertSame([2, 2, 0, 0], [$json['data']['absences'], $json['data']['unjustified_absences'], $json['data']['justified_absences'], $json['data']['lates']]);
        self::assertEquals(33.3, $json['data']['unjustified_rate_percent']);
        self::assertEquals(66.7, $json['data']['attendance_rate_percent']);
        self::assertSame('not_allowed', $json['data']['level']);
        self::assertEquals(['max_unjustified_percent' => 30.0, 'alert_unjustified_percent' => 25.0], $json['data']['thresholds']);
        self::assertSame('Mathématiques', $json['data']['by_subject'][0]['subject']);
        self::assertEquals(33.3, $json['data']['by_subject'][0]['unjustified_rate_percent']);
    }

    public function testLaPeriodeBorneLeCumul(): void
    {
        [, $json] = $this->api('GET', '/attendance/students/s3/summary?from=2026-09-17&to=2026-10-08', 'reader');

        self::assertSame(4, $json['data']['sessions']);
        self::assertEquals(25.0, $json['data']['unjustified_rate_percent']);
        self::assertSame('alert', $json['data']['level'], 'Alerte préventive à 25 %.');
        [$bad] = $this->api('GET', '/attendance/students/s3/summary?from=hier', 'reader');
        self::assertSame(422, $bad->getStatusCode());
    }

    public function testLaListeDesNonAutorisesAComposer(): void
    {
        [$response, $json] = $this->api('GET', '/attendance/not-allowed-to-sit', 'reader');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([['s2', 'not_allowed', 'Nadia Foka']], array_map(static fn (array $r): array => [$r['person_id'], $r['level'], $r['full_name']], $json['data']));
        self::assertEquals(30.0, $json['meta']['thresholds']['max_unjustified_percent']);
    }

    public function testLAlerteApparaitDansLaListeSurLaPeriode(): void
    {
        [, $json] = $this->api('GET', '/attendance/not-allowed-to-sit?from=2026-09-17&to=2026-10-08&structure_id=c1', 'reader');

        self::assertSame([['s3', 'alert']], array_map(static fn (array $r): array => [$r['person_id'], $r['level']], $json['data']));
    }

    public function testLeSeuilSeParametreParEtablissement(): void
    {
        $this->settings->values['attendance.max_unjustified_percent'] = 10;
        $this->settings->values['attendance.alert_unjustified_percent'] = 5;

        [, $json] = $this->api('GET', '/attendance/not-allowed-to-sit', 'reader');

        self::assertSame(['s2' => 'not_allowed', 's3' => 'not_allowed'], array_column($json['data'], 'level', 'person_id'));
    }

    public function testLeFiltreParClasseExclutLesAutres(): void
    {
        [, $json] = $this->api('GET', '/attendance/not-allowed-to-sit?structure_id=autre-classe', 'reader');

        self::assertSame([], $json['data']);
    }

    public function testUneAbsenceJustifieeSortDuNonJustifie(): void
    {
        $this->api('POST', '/absence-justifications', 'vs', ['person_id' => 's2', 'absence_from' => '2026-09-03', 'absence_to' => '2026-09-10', 'reason' => 'Paludisme']);
        $id = $this->rows('SELECT id FROM absence_justifications')[0]['id'];
        $this->api('POST', '/absence-justifications/' . $id . '/accept', 'vs');

        [, $json] = $this->api('GET', '/attendance/not-allowed-to-sit', 'reader');

        self::assertSame([], $json['data']);
    }

    public function testLaListeEstReserveAuxLecteurs(): void
    {
        foreach (['teacher', 'parent1', 'nobody'] as $user) {
            [$response] = $this->api('GET', '/attendance/not-allowed-to-sit', $user);
            self::assertSame(403, $response->getStatusCode(), $user);
        }
    }

    public function testLesContratsPublicsDuModuleSontLies(): void
    {
        $absentees = $this->container()->get(AbsenteeDirectory::class);
        $summary = $this->container()->get(AttendanceSummaryProvider::class);

        self::assertSame(['s2'], $absentees->absentLearnerIds('t1', 'e1', '2026-09-03'));
        self::assertSame([], $absentees->absentLearnerIds('t1', 'e1', '2026-09-17'));
        self::assertSame([], $absentees->absentLearnerIds('t2', 'e1', '2026-09-03'));
        self::assertSame([], $absentees->absentLearnerIds('t1', 'e2', '2026-09-03'));
        self::assertSame(1, $summary->absentCountOn('t1', 'e1', '2026-09-24'));

        $figures = $summary->forLearner('t1', 'e1', 's2', '2026-09-01', '2026-10-31');
        self::assertSame([6, 2, 2, 0, 33.3], [$figures->sessions, $figures->absences, $figures->unjustifiedAbsences, $figures->lates, $figures->unjustifiedRatePercent()]);
        self::assertSame(0, $summary->forLearner('t2', 'e1', 's2', '2026-09-01', '2026-10-31')->sessions);
        self::assertSame(0, $summary->forLearner('t1', 'e1', 's2', '2026-09-04', '2026-09-09')->sessions);
    }

    public function testSeulesLesSeancesValideesComptent(): void
    {
        [$response] = $this->api('POST', '/attendance-sessions', 'teacher', ['client_uuid' => self::ulid(), 'slot_id' => 'slot1', 'date' => '2026-09-03']);
        self::assertSame(409, $response->getStatusCode(), 'La séance du 3 septembre existe déjà (validée).');

        $this->rows("UPDATE attendance_sessions SET status = 'open' WHERE session_date = '2026-09-10'");
        [, $json] = $this->api('GET', '/attendance/students/s2/summary', 'reader');
        self::assertSame(5, $json['data']['sessions']);
    }
}
