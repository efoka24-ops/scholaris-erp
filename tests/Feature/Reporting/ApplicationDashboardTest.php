<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use Scholaris\Modules\Socle\Reporting\Contracts\ConfiguredDashboard;
use Scholaris\Modules\Socle\Reporting\Contracts\Indicator;
use Scholaris\Modules\Socle\Reporting\Contracts\IndicatorProvider;
use Scholaris\Modules\Socle\Reporting\Contracts\IndicatorScope;
use Tests\Support\Reporting\ReportingTestCase;

/** S21 : tableaux de bord déclarés par une application (ecole, academique, tutelle), permission propre, école interne transmise. */
final class ApplicationDashboardTest extends ReportingTestCase
{
    protected function setUp(): void
    {
        $this->bootReporting([
            'reporting.indicators.higher' => [SchoolRecovery::class],
            'reporting.dashboards.higher' => [
                new ConfiguredDashboard('ecole', 'Tableau de bord de l\'école', [
                    'higher.recovery_rate' => 'Taux de recouvrement de l\'école',
                    'people.enrolled' => 'Étudiants inscrits',
                    'higher.pass_rate' => 'Taux de réussite',
                ], 'higher.dashboard.read'),
                new ConfiguredDashboard('academique', 'Pilotage académique', ['higher.pass_rate' => 'Taux de réussite'], 'higher.dashboard.read'),
                new ConfiguredDashboard('tutelle', 'Tutelle', ['higher.recovery_rate' => 'Recouvrement'], 'higher.tutelage.read'),
                new ConfiguredDashboard('direction', 'Ne remplace pas le socle', ['higher.pass_rate' => 'X']),
            ],
        ]);
        foreach (['dg', 'director'] as $user) {
            $this->access->grant($user, 'e1');
        }
        $this->permissions->grant('dg', 'higher.dashboard.read');
        $this->permissions->grant('director', 'reporting.read');
        $this->enrollments->add('t1', 'en1', 'l1', 'e1');
    }

    public function testTableauDeBordEcoleAvecEcoleInterne(): void
    {
        [$response, $json] = $this->api('/dashboards/ecole?org_unit_id=ou-ec', 'dg', 't1', 'e1');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $data = $json['data'];
        self::assertSame('ecole', $data['kind']);
        self::assertSame(['type' => 'establishment', 'establishment_id' => 'e1', 'org_unit_id' => 'ou-ec'], $data['scope']);
        $byKey = $this->byKey($data);
        self::assertSame(['higher.recovery_rate', 'people.enrolled', 'higher.pass_rate'], array_slice(array_keys($byKey), 0, 3));
        self::assertSame(0.75, $byKey['higher.recovery_rate']['value']);
        self::assertSame('ou-ec', $byKey['higher.recovery_rate']['detail']['org_unit_id']);
        self::assertSame(1, $byKey['people.enrolled']['value']);
        self::assertFalse($byKey['higher.pass_rate']['available']);

        [$all] = $this->api('/dashboards/academique', 'dg', 't1', 'e1');
        self::assertSame(200, $all->getStatusCode());
    }

    public function testPermissionPropreEtTableauxDuSocleInchanges(): void
    {
        [$denied] = $this->api('/dashboards/ecole', 'director', 't1', 'e1');
        self::assertSame(403, $denied->getStatusCode());
        [$tutelle] = $this->api('/dashboards/tutelle', 'dg', 't1', 'e1');
        self::assertSame(403, $tutelle->getStatusCode());
        [$unknown] = $this->api('/dashboards/inconnu', 'dg', 't1', 'e1');
        self::assertSame(404, $unknown->getStatusCode());
        [$bad] = $this->api('/dashboards/ecole?org_unit_id=' . rawurlencode('a b'), 'dg', 't1', 'e1');
        self::assertSame(422, $bad->getStatusCode());

        // Une application ne remplace pas un tableau de bord du socle.
        [$direction, $json] = $this->api('/dashboards/direction', 'director', 't1', 'e1');
        self::assertSame(200, $direction->getStatusCode());
        self::assertSame('direction', $json['data']['kind']);
        self::assertSame(['type' => 'establishment', 'establishment_id' => 'e1'], $json['data']['scope']);
        self::assertArrayHasKey('people.enrolled', $this->byKey($json['data']));
    }
}

final class SchoolRecovery implements IndicatorProvider
{
    public function module(): string
    {
        return 'billing';
    }

    public function indicators(IndicatorScope $scope): array
    {
        return [Indicator::of('higher.recovery_rate', 'Taux de recouvrement de l\'école', 0.75, 'ratio', ['org_unit_id' => $scope->orgUnitId])];
    }
}
