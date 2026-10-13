<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use Scholaris\Modules\Socle\Assessment\Contracts\PublishedResult;
use Scholaris\Modules\Socle\Attendance\Contracts\AttendanceSummary;
use Scholaris\Modules\Socle\Reporting\Contracts\Indicator;
use Scholaris\Modules\Socle\Reporting\Contracts\IndicatorProvider;
use Scholaris\Modules\Socle\Reporting\Contracts\IndicatorScope;
use Scholaris\Modules\Socle\Scheduling\Contracts\SlotInfo;
use Tests\Support\Reporting\ReportingTestCase;

final class DashboardTest extends ReportingTestCase
{
    protected function setUp(): void
    {
        $this->bootReporting(['reporting.indicators.test' => [VacationHours::class, BrokenProvider::class]]);
        foreach (['director', 'censor', 'group'] as $user) {
            $this->access->grant($user, 'e1');
            $this->access->grant($user, 'e2');
        }
        $this->permissions->grant('director', 'reporting.read');
        $this->permissions->grant('censor', 'reporting.read', 'e1');
        $this->permissions->grant('group', 'reporting.read');
        $this->permissions->grant('group', 'reporting.group.read');

        $this->enrollments->add('t1', 'en1', 'l1', 'e1');
        $this->enrollments->add('t1', 'en2', 'l2', 'e1');
        $this->enrollments->add('t1', 'en3', 'l3', 'e2');
        $this->enrollments->add('t1', 'en4', 'l4', 'e1', 'c1', 'withdrawn');
        $this->debtors->setBalance('t1', 'e1', 'l1', 100000);
        $this->debtors->setBalance('t1', 'e1', 'l2', 50000);
        $this->debtors->setBalance('t1', 'e2', 'l3', 30000);
        $this->attendance->setAbsent('t1', 'e1', 3);
        $this->attendance->setAbsent('t1', 'e2', 1);
        $this->attendance->set('t1', 'e1', 'l1', new AttendanceSummary(10, 3, 2, 0));
        $this->attendance->set('t1', 'e1', 'l2', new AttendanceSummary(10, 1, 0, 1));
        $this->staff->addTeacher('t1', 'e1', 't-a');
        $this->staff->addTeacher('t1', 'e1', 't-b');
        $this->staff->addTeacher('t1', 'e2', 't-c');
        $this->timetable->add(new SlotInfo('s1', 't1', 'e1', 'c1', 't-a', 'Maths', 4, '08:00', '09:00', '2026-09-01'));
        $this->results->add('t1', new PublishedResult('r1', 'l1', 'e1', 'p1', 'S1', 12.0, 1, 2, null));
        $this->results->add('t1', new PublishedResult('r2', 'l2', 'e1', 'p1', 'S1', 14.0, 2, 2, null));
    }

    public function testLesTuilesDuLanceurPortentDeuxIndicateursSelonLeRole(): void
    {
        $tiles = $this->container()->get(\Scholaris\Modules\Socle\Identity\Contracts\TileIndicators::class);

        $direction = $tiles->forTile('t1', 'e1', ['directeur']);
        self::assertSame(['people.enrolled', 'billing.outstanding_xaf'], array_column($direction, 'key'));
        self::assertSame([2, 150000], array_column($direction, 'value'));
        self::assertSame(['people.enrolled', 'attendance.absent_today'], array_column($tiles->forTile('t1', 'e1', ['censeur']), 'key'));
        self::assertSame([], $tiles->forTile('t1', 'e1', ['enseignant', 'parent']));
        self::assertSame(1, $tiles->forTile('t1', 'e2', ['directeur'])[0]['value'], 'Chaque tuile calcule son établissement.');
    }

    public function testDirectionCalculeLesIndicateursViaLesContrats(): void
    {
        [$response, $json] = $this->api('/dashboards/direction', 'director', 't1', 'e1');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $i = $this->byKey($json['data']);

        self::assertSame(2, $i['people.enrolled']['value']);
        self::assertSame(2, $i['billing.learners_in_arrears']['value']);
        self::assertSame(150000, $i['billing.outstanding_xaf']['value']);
        self::assertSame(3, $i['attendance.absent_today']['value']);
        self::assertEquals(10.0, $i['attendance.week_unjustified_rate']['value']);
        self::assertSame(2, $i['staff.teachers_active']['value']);
        self::assertSame(1, $i['scheduling.slots_today']['value']);
        self::assertSame('establishment', $json['data']['scope']['type']);
    }

    public function testUnIndicateurSansSourceRenvoieAvailableFalse(): void
    {
        $i = $this->byKey($this->api('/dashboards/direction', 'director', 't1', 'e1')[1]['data']);

        foreach (['billing.recovery_rate', 'people.enrolment_growth'] as $key) {
            self::assertFalse($i[$key]['available'], $key);
            self::assertNull($i[$key]['value'], $key);
            self::assertNotEmpty($i[$key]['reason'], $key);
        }
    }

    public function testUnFournisseurEnregistreAlimenteUnIndicateurAttendu(): void
    {
        $i = $this->byKey($this->api('/dashboards/direction', 'director', 't1', 'e1')[1]['data']);

        self::assertTrue($i['staff.vacation_hours']['available']);
        self::assertSame(642, $i['staff.vacation_hours']['value']);
    }

    public function testUnFournisseurDefaillantDegradeSansCasserLeTableau(): void
    {
        [$response, $json] = $this->api('/dashboards/direction', 'director', 't1', 'e1');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([BrokenProvider::class], $json['data']['meta']['degraded_providers']);
    }

    public function testCenseurNeRenvoiePasDeNoteNonPubliee(): void
    {
        $i = $this->byKey($this->api('/dashboards/censeur', 'censor', 't1', 'e1')[1]['data']);

        self::assertEquals(13.0, $i['assessment.published_average']['value']);
        self::assertFalse($i['assessment.entry_progress']['available']);
        self::assertFalse($i['discipline.pending_validation']['available']);
    }

    public function testUnModuleDesactiveRendSesIndicateursIndisponibles(): void
    {
        $this->modules->disable('e1', 'billing');
        $i = $this->byKey($this->api('/dashboards/direction', 'director', 't1', 'e1')[1]['data']);

        self::assertFalse($i['billing.outstanding_xaf']['available']);
        self::assertTrue($i['people.enrolled']['available']);
    }

    public function testVueGroupeConsolideLesEtablissements(): void
    {
        [$response, $json] = $this->api('/dashboards/groupe', 'group');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $data = $json['data'];

        $totals = [];
        foreach ($data['consolidated'] as $row) {
            $totals[$row['key']] = $row;
        }
        self::assertSame(3, $totals['people.enrolled']['value']);
        self::assertSame(180000, $totals['billing.outstanding_xaf']['value']);
        self::assertSame(4, $totals['attendance.absent_today']['value']);
        self::assertSame(2, $totals['people.enrolled']['detail']['establishments_counted']);
        self::assertSame(['e1', 'e2'], array_column($data['establishments'], 'establishment_id'));
    }

    public function testVueGroupeReserveeALaDirectionDeGroupe(): void
    {
        self::assertSame(403, $this->api('/dashboards/groupe', 'director', 't1', 'e1')[0]->getStatusCode());
        self::assertSame(403, $this->api('/dashboards/groupe', 'censor', 't1', 'e1')[0]->getStatusCode());
    }

    public function testLeDroitDeLectureEstBorneAEtablissement(): void
    {
        self::assertSame(200, $this->api('/dashboards/censeur', 'censor', 't1', 'e1')[0]->getStatusCode());
        self::assertSame(403, $this->api('/dashboards/censeur', 'censor', 't1', 'e2')[0]->getStatusCode());
    }

    public function testEtablissementObligatoireEtKindInconnu(): void
    {
        self::assertSame(400, $this->api('/dashboards/direction', 'director')[0]->getStatusCode());
        self::assertSame(404, $this->api('/dashboards/inconnu', 'director', 't1', 'e1')[0]->getStatusCode());
    }
}

/** Fournisseur d'application : heures de vacation (valeur de test, pas une donnée réelle). */
final class VacationHours implements IndicatorProvider
{
    public function module(): string
    {
        return 'staff';
    }

    public function indicators(IndicatorScope $scope): array
    {
        return [Indicator::of('staff.vacation_hours', 'Heures de vacation', 642, 'hours')];
    }
}

final class BrokenProvider implements IndicatorProvider
{
    public function module(): string
    {
        return 'people';
    }

    public function indicators(IndicatorScope $scope): array
    {
        throw new \RuntimeException('source indisponible');
    }
}
