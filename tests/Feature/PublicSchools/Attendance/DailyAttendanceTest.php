<?php

declare(strict_types=1);

namespace Tests\Feature\PublicSchools\Attendance;

use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;
use Tests\Support\PublicSchools\PublicSchoolsTestCase;

/**
 * T634 : appel d'une classe par demi-journée (API-05) sur le contrat d'écriture du socle.
 * Tous présents par défaut ; correction par l'enseignant le jour J ; refus à J+1 sauf directeur avec motif (RG-PRE-1).
 */
final class DailyAttendanceTest extends PublicSchoolsTestCase
{
    protected function setUp(): void
    {
        $this->bootPublic();
    }

    public function testToutLeMondeEstPresentParDefautSansAucuneException(): void
    {
        [$response, $json] = $this->putAttendance('teacher', 'c1', self::DAY, 'morning');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('validated', $json['data']['status']);
        self::assertSame('morning', $json['data']['day_part']);
        $statuses = array_column($json['data']['records'], 'status', 'person_id');
        self::assertSame(['s1' => 'P', 's2' => 'P', 's3' => 'P'], $statuses);
        $calls = $this->rows('SELECT structure_id, day_on, day_part, headcount, absent_count, late_count, taken_by FROM pub_attendance_calls');
        self::assertSame([['structure_id' => 'c1', 'day_on' => self::DAY, 'day_part' => 'morning', 'headcount' => 3, 'absent_count' => 0, 'late_count' => 0, 'taken_by' => 'teacher']], $calls);
    }

    public function testSeulesLesExceptionsSontSaisiesEtLaFamilleDeLAbsentEstPrevenue(): void
    {
        [$response, $json] = $this->putAttendance('teacher', 'c1', self::DAY, 'morning', [
            ['person_id' => 's2', 'status' => 'A', 'note' => 'Fièvre signalée par SMS'],
            ['person_id' => 's3', 'status' => 'R', 'arrived_at' => '08:20'],
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(['s1' => 'P', 's2' => 'A', 's3' => 'R'], array_column($json['data']['records'], 'status', 'person_id'));
        $notices = $this->messenger->of('absence_notice');
        self::assertCount(1, $notices, 'NOT-02 : la famille de l\'absent est prévenue par le socle.');
        self::assertSame('p-parent2', $notices[0]['person']);
        self::assertSame(1, (int) $this->rows('SELECT absent_count FROM pub_attendance_calls')[0]['absent_count']);
        self::assertSame(1, (int) $this->rows('SELECT late_count FROM pub_attendance_calls')[0]['late_count']);
    }

    public function testUnRejouIdentiqueNeCreeNiDoublonNiNouvelleNotification(): void
    {
        $uuid = self::ulid();
        $records = [['person_id' => 's2', 'status' => 'A']];
        $this->putAttendance('teacher', 'c1', self::DAY, 'morning', $records, null, $uuid);
        [$again, $json] = $this->putAttendance('teacher', 'c1', self::DAY, 'morning', $records, null, $uuid);

        self::assertSame(200, $again->getStatusCode(), (string) $again->getBody());
        self::assertSame('validated', $json['data']['status']);
        self::assertCount(1, $this->rows('SELECT id FROM attendance_sessions'));
        self::assertCount(1, $this->rows('SELECT id FROM pub_attendance_calls'));
        self::assertCount(1, $this->messenger->of('absence_notice'));
    }

    public function testLEnseignantCorrigeSonAppelLeJourMeme(): void
    {
        $this->putAttendance('teacher', 'c1', self::DAY, 'morning', [['person_id' => 's2', 'status' => 'A']]);

        [$response, $json] = $this->putAttendance('teacher', 'c1', self::DAY, 'morning', [['person_id' => 's3', 'status' => 'A']]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(['s1' => 'P', 's2' => 'P', 's3' => 'A'], array_column($json['data']['records'], 'status', 'person_id'));
        $call = $this->rows('SELECT absent_count, corrected_by FROM pub_attendance_calls')[0];
        self::assertSame(1, (int) $call['absent_count']);
        self::assertSame('teacher', $call['corrected_by']);
        self::assertCount(1, $this->rows('SELECT id FROM attendance_sessions'), 'La correction réécrit la séance, elle n\'en crée pas une seconde.');
    }

    public function testLesDeuxDemiJourneesSontDeuxAppelsDistincts(): void
    {
        $this->putAttendance('teacher', 'c1', self::DAY, 'morning', [['person_id' => 's2', 'status' => 'A']]);
        [$response, $json] = $this->putAttendance('teacher', 'c1', self::DAY, 'afternoon');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('afternoon', $json['data']['day_part']);
        self::assertCount(2, $this->rows('SELECT id FROM attendance_sessions'));
        self::assertCount(2, $this->rows('SELECT id FROM pub_attendance_calls'));
    }

    public function testLendemainLEnseignantNePeutPlusCorrigerSonAppel(): void
    {
        $this->putAttendance('teacher', 'c1', self::DAY, 'morning');
        $this->setNow('2026-10-09T09:00:00+01:00');

        [$response] = $this->putAttendance('teacher', 'c1', self::DAY, 'morning', [['person_id' => 's2', 'status' => 'A']], 'Oubli');

        self::assertSame(403, $response->getStatusCode(), (string) $response->getBody());
        $statuses = array_column($this->rows('SELECT person_id, status FROM attendance_records ORDER BY person_id'), 'status', 'person_id');
        self::assertSame(['s1' => 'P', 's2' => 'P', 's3' => 'P'], $statuses, 'Aucune donnée n\'a été modifiée.');
    }

    public function testLendemainLeDirecteurCorrigeAvecUnMotif(): void
    {
        $this->putAttendance('teacher', 'c1', self::DAY, 'morning');
        $this->setNow('2026-10-09T09:00:00+01:00');

        [$response, $json] = $this->putAttendance('director', 'c1', self::DAY, 'morning', [['person_id' => 's2', 'status' => 'A']], 'Absence confirmée par le parent SYN');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('A', array_column($json['data']['records'], 'status', 'person_id')['s2']);
        $call = $this->rows('SELECT corrected_by, correction_reason FROM pub_attendance_calls')[0];
        self::assertSame('director', $call['corrected_by']);
        self::assertSame('Absence confirmée par le parent SYN', $call['correction_reason']);
        $audit = $this->rows("SELECT action FROM audit_log WHERE action = 'attendance.session.corrected'");
        self::assertCount(1, $audit);
    }

    public function testLendemainLeDirecteurSansMotifEstRefuse(): void
    {
        $this->putAttendance('teacher', 'c1', self::DAY, 'morning');
        $this->setNow('2026-10-09T09:00:00+01:00');

        [$response, $json] = $this->putAttendance('director', 'c1', self::DAY, 'morning', [['person_id' => 's2', 'status' => 'A']]);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        self::assertArrayHasKey('reason', $json['errors']);
    }

    public function testLeDirecteurCorrigeLeJourMemeUnAppelFaitParUnEnseignant(): void
    {
        $this->putAttendance('teacher', 'c1', self::DAY, 'morning');

        [$response] = $this->putAttendance('director', 'c1', self::DAY, 'morning', [['person_id' => 's1', 'status' => 'A']]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    }

    public function testUnEnseignantNAppellePasLaClasseDUnCollegue(): void
    {
        [$response] = $this->putAttendance('teacher2', 'c1', self::DAY, 'morning');

        self::assertSame(403, $response->getStatusCode());
        self::assertSame([], $this->rows('SELECT id FROM attendance_sessions'));
        self::assertCount(1, $this->rows("SELECT action FROM audit_log WHERE action = 'public_schools.scope.denied'"), 'Le refus hors périmètre est journalisé.');
    }

    public function testSansPermissionOuAvecUnPerimetreVideLAppelEstRefuse(): void
    {
        [$outsider] = $this->putAttendance('outsider', 'c1', self::DAY, 'morning');
        [$inspector] = $this->putAttendance('inspector', 'c1', self::DAY, 'morning');

        self::assertSame(403, $outsider->getStatusCode());
        self::assertSame(403, $inspector->getStatusCode(), 'La supervision territoriale ne fait pas l\'appel.');
    }

    public function testUneClasseDUneAutreEcoleOuDUnAutreClientEstIntrouvable(): void
    {
        [$otherSchool] = $this->api('PUT', '/public/classes/c1/attendance/' . self::DAY, 'director', ['day_part' => 'morning', 'client_uuid' => self::ulid()], 't1', 'e2');
        [$unknown] = $this->putAttendance('director', 'c-inconnue', self::DAY, 'morning');

        self::assertContains($otherSchool->getStatusCode(), [403, 404]);
        self::assertSame(404, $unknown->getStatusCode());
        self::assertSame([], $this->rows('SELECT id FROM attendance_sessions'));
    }

    public function testLApplicationSuspendueRefuseEcritureAvec423EtFermeeAvec403(): void
    {
        $this->applications->set('t1', 'public_primary', ApplicationAccess::SUSPENDED);
        [$suspended] = $this->putAttendance('teacher', 'c1', self::DAY, 'morning');
        $this->applications->set('t1', 'public_primary', null);
        [$closed] = $this->putAttendance('teacher', 'c1', self::DAY, 'morning');

        self::assertSame(423, $suspended->getStatusCode());
        self::assertSame(403, $closed->getStatusCode());
        self::assertSame([], $this->rows('SELECT id FROM attendance_sessions'));
    }

    public function testLesEntreesInvalidesSontRefuseesParChamp(): void
    {
        [$status, $statusJson] = $this->putAttendance('teacher', 'c1', self::DAY, 'morning', [['person_id' => 's2', 'status' => 'X']]);
        [$part, $partJson] = $this->putAttendance('teacher', 'c1', self::DAY, 'night');
        [$date, $dateJson] = $this->putAttendance('teacher', 'c1', '2026-13-45', 'morning');
        [$future, $futureJson] = $this->putAttendance('teacher', 'c1', '2026-10-09', 'morning');
        [$stranger, $strangerJson] = $this->putAttendance('teacher', 'c1', self::DAY, 'morning', [['person_id' => 's4', 'status' => 'A']]);

        self::assertSame(422, $status->getStatusCode());
        self::assertArrayHasKey('records.0.status', $statusJson['errors']);
        self::assertSame(422, $part->getStatusCode());
        self::assertArrayHasKey('day_part', $partJson['errors']);
        self::assertSame(422, $date->getStatusCode());
        self::assertArrayHasKey('date', $dateJson['errors']);
        self::assertSame(422, $future->getStatusCode(), 'Pas d\'appel pour un jour à venir.');
        self::assertArrayHasKey('date', $futureJson['errors']);
        self::assertSame(422, $stranger->getStatusCode(), 'Un élève d\'une autre classe n\'est pas une exception de cette classe.');
        self::assertArrayHasKey('records', $strangerJson['errors']);
    }

    public function testLEtatDuJourMontreLesDemiJourneesFaitesEtManquantes(): void
    {
        $this->putAttendance('teacher', 'c1', self::DAY, 'morning', [['person_id' => 's2', 'status' => 'A']]);

        [$response, $json] = $this->api('GET', '/public/classes/c1/attendance/' . self::DAY, 'teacher');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(3, $json['data']['headcount']);
        self::assertTrue($json['data']['parts'][0]['taken']);
        self::assertSame(['s2'], $json['data']['parts'][0]['absent_person_ids']);
        self::assertFalse($json['data']['parts'][1]['taken']);
        self::assertArrayNotHasKey('absent_person_ids', $json['data']['parts'][1]);
    }

    public function testLaSupervisionTerritorialeNeLitQueDesEffectifs(): void
    {
        $this->putAttendance('teacher', 'c1', self::DAY, 'morning', [['person_id' => 's2', 'status' => 'A']]);

        [$response, $json] = $this->api('GET', '/public/classes/c1/attendance/' . self::DAY, 'inspector');
        [$register] = $this->api('GET', '/public/classes/c1/attendance-register?month=2026-10', 'inspector');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(1, $json['data']['parts'][0]['absent_count']);
        self::assertArrayNotHasKey('absent_person_ids', $json['data']['parts'][0], 'RG-TER-2 : aucun nom pour la supervision.');
        self::assertSame(403, $register->getStatusCode());
        self::assertStringNotContainsString('s2', (string) $response->getBody());
    }

    public function testUnEnseignantNeLitPasLEtatDeLaClasseDUnCollegue(): void
    {
        [$response] = $this->api('GET', '/public/classes/c1/attendance/' . self::DAY, 'teacher2');
        [$register] = $this->api('GET', '/public/classes/c1/attendance-register?month=2026-10', 'teacher2');

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(404, $register->getStatusCode());
    }

    public function testLeCahierDAppelMensuelListeLesStatutsParDemiJournee(): void
    {
        $this->putAttendance('teacher', 'c1', '2026-10-06', 'morning', [['person_id' => 's2', 'status' => 'A']]);
        $this->putAttendance('teacher', 'c1', '2026-10-06', 'afternoon');
        $this->putAttendance('teacher', 'c1', '2026-10-07', 'morning', [['person_id' => 's2', 'status' => 'A'], ['person_id' => 's3', 'status' => 'A']]);

        [$response, $json] = $this->api('GET', '/public/classes/c1/attendance-register?month=2026-10', 'teacher');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('CP1', $json['data']['class']['label']);
        self::assertSame(['2026-10-06', '2026-10-07'], array_column($json['data']['days'], 'date'));
        $byPupil = array_column($json['data']['pupils'], null, 'person_id');
        self::assertSame(2, $byPupil['s2']['absent_half_days']);
        self::assertSame('A', $byPupil['s2']['marks']['2026-10-06']['morning']);
        self::assertSame('P', $byPupil['s2']['marks']['2026-10-06']['afternoon']);
        self::assertSame('Nadia Foka', $byPupil['s2']['name']);
        self::assertSame(0, $byPupil['s1']['absent_half_days']);
        [$bad] = $this->api('GET', '/public/classes/c1/attendance-register?month=2026-1', 'teacher');
        self::assertSame(422, $bad->getStatusCode());
    }
}
