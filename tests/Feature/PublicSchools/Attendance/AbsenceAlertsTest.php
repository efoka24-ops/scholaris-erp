<?php

declare(strict_types=1);

namespace Tests\Feature\PublicSchools\Attendance;

use Scholaris\Modules\Apps\PublicSchools\Attendance\Application\AbsenceAlertService;
use Scholaris\Modules\Apps\PublicSchools\Attendance\Console\AbsenceAlertsCommand;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;
use Tests\Support\PublicSchools\PublicSchoolsTestCase;

/**
 * T638 : alertes d'assiduité. NOT-02 est envoyée par le socle à la validation de l'appel (voir DailyAttendanceTest) ;
 * NOT-03 (absences répétées) et NOT-12 (classe sans appel) sont levées par `public:absence-alerts`.
 */
final class AbsenceAlertsTest extends PublicSchoolsTestCase
{
    protected function setUp(): void
    {
        $this->bootPublic();
    }

    private function service(): AbsenceAlertService
    {
        return $this->container()->get(AbsenceAlertService::class);
    }

    /** @param list<string> $days */
    private function absentMornings(array $days, string $pupil = 's2'): void
    {
        foreach ($days as $day) {
            [$response] = $this->putAttendance('teacher', 'c1', $day, 'morning', [['person_id' => $pupil, 'status' => 'A']]);
            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        }
    }

    public function testCinqJoursDAbsenceDansLeMoisPrevientLaFamilleEtLeDirecteurUneSeuleFois(): void
    {
        $this->absentMornings(['2026-10-01', '2026-10-02', '2026-10-05', '2026-10-06', '2026-10-07']);
        $this->messenger->sent = [];

        $result = $this->service()->repeatedAbsences('t1', 'e1', '2026-10-08');

        self::assertSame(['alerts' => 1, 'messages' => 2], $result);
        $parents = $this->messenger->of('pub.not03.parent');
        self::assertCount(1, $parents);
        self::assertSame('p-parent2', $parents[0]['person']);
        self::assertSame('5', $parents[0]['variables']['details']);
        self::assertSame('Nadia Foka', $parents[0]['variables']['child_name']);
        $director = $this->messenger->of('pub.not03.director');
        self::assertCount(1, $director);
        self::assertSame('p-director', $director[0]['person']);
        self::assertSame('CP1', $director[0]['variables']['class_name']);
        self::assertSame(['alerts' => 0, 'messages' => 0], $this->service()->repeatedAbsences('t1', 'e1', '2026-10-09'), 'Une seule alerte par élève et par mois.');
        self::assertCount(1, $this->rows("SELECT id FROM pub_attendance_alerts WHERE kind = 'repeated_absence'"));
    }

    public function testQuatreJoursNeDeclenchentRienEtLeSeuilEstUnParametre(): void
    {
        $this->absentMornings(['2026-10-01', '2026-10-02', '2026-10-05', '2026-10-06']);

        self::assertSame(['alerts' => 0, 'messages' => 0], $this->service()->repeatedAbsences('t1', 'e1', '2026-10-08'));

        $this->settings->values['public_schools.absence.repeated_days'] = 4;
        self::assertSame(1, $this->service()->repeatedAbsences('t1', 'e1', '2026-10-08')['alerts']);
    }

    public function testDeuxDemiJourneesLeMemeJourCompteNtPourUnSeulJour(): void
    {
        foreach (['2026-10-01', '2026-10-02', '2026-10-05', '2026-10-06'] as $day) {
            $this->putAttendance('teacher', 'c1', $day, 'morning', [['person_id' => 's2', 'status' => 'A']]);
            $this->putAttendance('teacher', 'c1', $day, 'afternoon', [['person_id' => 's2', 'status' => 'A']]);
        }

        self::assertSame(0, $this->service()->repeatedAbsences('t1', 'e1', '2026-10-08')['alerts'], 'Huit demi-journées, quatre jours.');
    }

    public function testLaFenetreGlissanteCompteLesAbsencesDesJoursPrecedents(): void
    {
        $this->absentMornings(['2026-09-28', '2026-09-29', '2026-09-30', '2026-10-01', '2026-10-02']);
        $this->settings->values['public_schools.absence.window'] = 'month';

        self::assertSame(0, $this->service()->repeatedAbsences('t1', 'e1', '2026-10-08')['alerts'], 'Mois civil : seuls deux jours sont en octobre.');

        $this->settings->values['public_schools.absence.window'] = 'rolling';
        $this->settings->values['public_schools.absence.window_days'] = 14;
        self::assertSame(1, $this->service()->repeatedAbsences('t1', 'e1', '2026-10-08')['alerts']);
    }

    public function testUneClasseSansAppelDepuisTroisJoursOuvresPrevientLeDirecteur(): void
    {
        $this->putAttendance('teacher', 'c1', '2026-10-02', 'morning');
        $this->messenger->sent = [];

        $result = $this->service()->missingCalls('t1', 'e1', '2026-10-08');

        $c1 = $this->rows("SELECT level, period_key FROM pub_attendance_alerts WHERE kind = 'no_call' AND subject_id = 'c1'");
        self::assertSame([['level' => 'director', 'period_key' => '2026-10-05']], $c1, 'Trois jours ouvrés (5, 6 et 7 octobre) après le dernier appel du 2.');
        $notices = array_values(array_filter($this->messenger->of('pub.not12.director'), static fn (array $m): bool => $m['variables']['class_name'] === 'CP1'));
        self::assertCount(1, $notices);
        self::assertSame('3', $notices[0]['variables']['details']);
        self::assertSame('2026-10-07', $notices[0]['variables']['date']);
        self::assertGreaterThanOrEqual(1, $result['alerts']);
        self::assertSame(0, $this->service()->missingCalls('t1', 'e1', '2026-10-08')['alerts'], 'Pas de nouvelle alerte pour la même série de jours sans appel.');
    }

    public function testUneClasseJamaisAppeleeMonteALInspectionAuSeuilDEscalade(): void
    {
        $this->putAttendance('teacher', 'c1', '2026-10-02', 'morning');

        $this->service()->missingCalls('t1', 'e1', '2026-10-08');

        $c2 = $this->rows("SELECT level, period_key FROM pub_attendance_alerts WHERE kind = 'no_call' AND subject_id = 'c2' ORDER BY level");
        self::assertSame([['level' => 'director', 'period_key' => 'never'], ['level' => 'inspection', 'period_key' => 'never']], $c2);
        $c1 = $this->rows("SELECT level FROM pub_attendance_alerts WHERE kind = 'no_call' AND subject_id = 'c1'");
        self::assertSame([['level' => 'director']], $c1, 'Trois jours sans appel : le directeur seulement ; l\'inspection à cinq jours.');
    }

    public function testLesJoursFermesEtLesWeekEndsNeComptentPasEnJoursOuvres(): void
    {
        $this->putAttendance('teacher', 'c1', '2026-10-02', 'morning');
        $this->putAttendance('director', 'c2', '2026-10-02', 'morning');
        $this->settings->values['public_schools.calendar.closed_days'] = ['2026-10-05', '2026-10-06'];

        $this->service()->missingCalls('t1', 'e1', '2026-10-08');

        self::assertSame([], $this->rows("SELECT id FROM pub_attendance_alerts WHERE kind = 'no_call'"), 'Seul le 7 octobre est ouvré depuis le dernier appel.');
    }

    public function testHorsPeriodeScolaireAucuneAlerteNEstLevee(): void
    {
        $this->putAttendance('teacher', 'c1', '2026-10-02', 'morning');

        self::assertSame(['alerts' => 0, 'messages' => 0], $this->service()->missingCalls('t1', 'e1', '2026-08-20'));
    }

    public function testUnClientSuspenduEstSauteParLaCommande(): void
    {
        $this->putAttendance('teacher', 'c1', '2026-10-02', 'morning');
        $this->applications->set('t1', 'public_primary', ApplicationAccess::SUSPENDED);

        $result = $this->service()->run('2026-10-08');

        self::assertSame(['alerts' => 0, 'messages' => 0, 'skipped_tenants' => 1], $result);
        self::assertSame([], $this->rows('SELECT id FROM pub_attendance_alerts'));
    }

    public function testLaCommandeLanceLesDeuxAlertesEtRefuseUneDateInvalide(): void
    {
        $this->absentMornings(['2026-10-01', '2026-10-02', '2026-10-05', '2026-10-06', '2026-10-07']);
        $command = $this->container()->get('console.command.' . AbsenceAlertsCommand::NAME);
        self::assertInstanceOf(AbsenceAlertsCommand::class, $command);

        ob_start();
        $status = $command->run(['2026-10-08']);
        $output = (string) ob_get_clean();

        self::assertSame(0, $status);
        self::assertStringContainsString('alerte(s)', $output);
        self::assertNotSame([], $this->rows("SELECT id FROM pub_attendance_alerts WHERE kind = 'repeated_absence'"));
        self::assertSame(2, $command->run(['2026-02-31']));
    }

    public function testAucuneSanctionAutomatiqueNEstCreee(): void
    {
        $this->absentMornings(['2026-10-01', '2026-10-02', '2026-10-05', '2026-10-06', '2026-10-07']);
        $this->service()->run('2026-10-08');

        self::assertSame([], $this->rows('SELECT id FROM sanctions'), 'RG-PRE-3 : les absences nourrissent le suivi, jamais une sanction.');
    }

    public function testLeFilDesAlertesDuDirecteurMontreLesAbsencesRepetees(): void
    {
        $this->absentMornings(['2026-10-01', '2026-10-02', '2026-10-05', '2026-10-06', '2026-10-07']);
        $this->service()->repeatedAbsences('t1', 'e1', '2026-10-08');

        [$director, $json] = $this->api('GET', '/public/attendance-alerts?kind=repeated_absence', 'director');
        [$inspector, $inspectorJson] = $this->api('GET', '/public/attendance-alerts?kind=repeated_absence', 'inspector');

        self::assertSame(200, $director->getStatusCode(), (string) $director->getBody());
        self::assertSame(['s2'], array_column($json['data'], 'subject_id'));
        self::assertSame(200, $inspector->getStatusCode());
        self::assertSame([], $inspectorJson['data'], 'RG-TER-2 : l\'inspection ne voit jamais l\'alerte nominative d\'un élève.');
    }
}
