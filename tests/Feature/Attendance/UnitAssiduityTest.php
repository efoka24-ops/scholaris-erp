<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use Scholaris\Modules\Socle\Attendance\Contracts\AttendanceCodes;
use Scholaris\Modules\Socle\Attendance\Contracts\AttendanceSummaryProvider;
use Scholaris\Modules\Socle\Attendance\Contracts\UnitAttendanceSummaryProvider;
use Scholaris\Modules\Socle\Scheduling\Contracts\SlotInfo;
use Tests\Support\Attendance\AttendanceTestCase;

/**
 * Assiduité par élément constitutif (D19), base paramétrable (D20). Exemple du CDC (RG-PRE-1, seuil 30 % par EC, alerte 25 %) :
 * 4 absences non justifiées sur 12 séances tenues de l'EC (33,3 %) : non autorisé à composer ; 3 sur 12 (25 %) : alerte seulement.
 * L'EC « EC1 » a cours chaque jour ouvré de 10 h à 12 h ; les 12 premiers jours ouvrés à partir du 14 septembre 2026 ont un appel.
 * Le créneau « slot1 » (mathématiques, sans EC) a six appels le jeudi où tout le monde est présent.
 */
final class UnitAssiduityTest extends AttendanceTestCase
{
    /** @var list<string> */
    private array $days = [];

    protected function setUp(): void
    {
        $this->bootAttendance();
        for ($weekday = 1; $weekday <= 5; ++$weekday) {
            $this->timetable->add(new SlotInfo('ec1-d' . $weekday, 't1', 'e1', 'c1', 'p-teacher', 'Comptabilité générale', $weekday, '10:00', '12:00', '2026-09-01', null, 'B12', 'EC1', 'cm'));
        }
        for ($day = new \DateTimeImmutable('2026-09-14'); count($this->days) < 12; $day = $day->modify('+1 day')) {
            if ((int) $day->format('N') <= 5) {
                $this->days[] = $day->format('Y-m-d');
            }
        }
        $absent = ['s1' => array_slice($this->days, 0, 4), 's2' => array_slice($this->days, 2, 4), 's3' => array_slice($this->days, 5, 3)];
        foreach ($this->days as $date) {
            $this->rollCall('ec1-d' . (new \DateTimeImmutable($date))->format('N'), $date, $absent);
        }
        foreach (['2026-09-03', '2026-09-10', '2026-09-17', '2026-09-24', '2026-10-01', '2026-10-08'] as $thursday) {
            $this->rollCall('slot1', $thursday, []);
        }
        // une absence de s1 est justifiée : 3 non justifiées sur 12
        $this->connections()->pdo()->exec(sprintf(
            "UPDATE attendance_records SET justified = 1 WHERE person_id = 's1' AND session_date = '%s'",
            $this->days[0],
        ));
    }

    /** @param array<string, list<string>> $absent */
    private function rollCall(string $slotId, string $date, array $absent): void
    {
        $records = [];
        foreach (['s1', 's2', 's3'] as $learner) {
            $records[] = ['person_id' => $learner, 'status' => in_array($date, $absent[$learner] ?? [], true) ? 'A' : 'P'];
        }
        [$response] = $this->api('POST', '/attendance-sessions', 'teacher', ['client_uuid' => self::ulid(), 'slot_id' => $slotId, 'date' => $date, 'records' => $records, 'validate' => true]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
    }

    private function units(): UnitAttendanceSummaryProvider
    {
        return $this->container()->get(UnitAttendanceSummaryProvider::class);
    }

    public function testLaSeanceDAppelHeriteDeLElementConstitutifDuCreneau(): void
    {
        $rows = $this->rows('SELECT course_unit_id, COUNT(*) AS n FROM attendance_sessions GROUP BY course_unit_id ORDER BY course_unit_id');

        self::assertSame([[null, 6], ['EC1', 12]], array_map(static fn (array $r): array => [$r['course_unit_id'], (int) $r['n']], $rows));
    }

    public function testLeSeuilDe30PourcentSAppliqueParElementConstitutif(): void
    {
        $units = $this->units();
        $s2 = $units->forLearnerByUnit('t1', 'e1', 's2', 'EC1');
        self::assertSame([12, 4, 4, 0], [$s2->sessions, $s2->absences, $s2->unjustifiedAbsences, $s2->lates]);
        self::assertEqualsWithDelta(33.3, $s2->unjustifiedRatePercent(), 0.001);

        $s3 = $units->forLearnerByUnit('t1', 'e1', 's3', 'EC1');
        self::assertEqualsWithDelta(25.0, $s3->unjustifiedRatePercent(), 0.001);
        $s1 = $units->forLearnerByUnit('t1', 'e1', 's1', 'EC1');
        self::assertSame([4, 3], [$s1->absences, $s1->unjustifiedAbsences], 'l\'absence justifiée ne compte pas');

        self::assertSame(['s2'], array_keys($units->learnersAbove('t1', 'e1', 'EC1', 30.0)), '33 % : non autorisé à composer');
        self::assertSame(['s1', 's2', 's3'], array_keys($units->learnersAbove('t1', 'e1', 'EC1', 24.9)), '25 % : au seuil d\'alerte');
        self::assertSame(['EC1'], array_keys($units->unitsAbove('t1', 'e1', 's2', 30.0)));
        self::assertSame([], $units->unitsAbove('t1', 'e1', 's3', 30.0));

        // par personne, toutes séances confondues, s2 n'atteint pas le seuil : 4 sur 18
        $person = $this->container()->get(AttendanceSummaryProvider::class)->forLearner('t1', 'e1', 's2', '2026-09-01', '2026-10-31');
        self::assertSame(18, $person->sessions);
        self::assertLessThan(30.0, $person->unjustifiedRatePercent());
    }

    public function testLeResumeHttpDonneLeNiveauParElementConstitutif(): void
    {
        [, $json] = $this->api('GET', '/attendance/students/s2/summary', 'reader');

        self::assertSame(AttendanceCodes::BASE_HELD, $json['data']['base']);
        self::assertSame('ok', $json['data']['level'], 'par personne : 4 sur 18 = 22,2 %, sous l\'alerte');
        self::assertSame([['EC1', 12, 'not_allowed']], array_map(static fn (array $u): array => [$u['course_unit_id'], $u['sessions'], $u['level']], $json['data']['by_course_unit']));
        [, $s3] = $this->api('GET', '/attendance/students/s3/summary', 'reader');
        self::assertSame('alert', $s3['data']['by_course_unit'][0]['level']);
    }

    public function testLaBaseProgrammeeCompteLesSeancesPrevuesDeLEmploiDuTemps(): void
    {
        $units = $this->units();
        // trois jours ouvrés de plus sont programmés sans appel (30 septembre, 1er et 2 octobre) : 4 sur 15
        $to = '2026-10-02';
        self::assertSame(12, $units->forLearnerByUnit('t1', 'e1', 's2', 'EC1', '2026-09-14', $to)->sessions, 'base « held » par défaut');

        $this->settings->values['attendance.base'] = AttendanceCodes::BASE_PLANNED;
        $planned = $units->forLearnerByUnit('t1', 'e1', 's2', 'EC1', '2026-09-14', $to);
        self::assertSame([15, 4], [$planned->sessions, $planned->unjustifiedAbsences]);
        self::assertEqualsWithDelta(26.7, $planned->unjustifiedRatePercent(), 0.001);
        self::assertSame([], $units->learnersAbove('t1', 'e1', 'EC1', 30.0, '2026-09-14', $to));

        $this->timetable->cancel('ec1-d3', '2026-09-30');
        self::assertSame(14, $units->forLearnerByUnit('t1', 'e1', 's2', 'EC1', '2026-09-14', $to)->sessions, 'séance annulée exclue');
        self::assertSame(12, $units->forLearnerByUnit('t1', 'e1', 's2', 'EC1')->sessions, 'sans bornes : séances tenues');

        $this->settings->values['attendance.base'] = 'autre';
        self::assertSame(12, $units->forLearnerByUnit('t1', 'e1', 's2', 'EC1', '2026-09-14', $to)->sessions, 'valeur inconnue : séances tenues');
    }

    public function testAucuneFuiteEntreClientsEtEtablissements(): void
    {
        $units = $this->units();

        self::assertSame(0, $units->forLearnerByUnit('t2', 'e1', 's2', 'EC1')->sessions);
        self::assertSame(0, $units->forLearnerByUnit('t1', 'e2', 's2', 'EC1')->sessions);
        self::assertSame([], $units->learnersAbove('t2', 'e1', 'EC1', 0.0));
        self::assertSame(0, $units->forLearnerByUnit('t1', 'e1', 's2', 'EC-inconnu')->sessions);
    }
}
