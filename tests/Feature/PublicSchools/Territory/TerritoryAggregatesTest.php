<?php

declare(strict_types=1);

namespace Tests\Feature\PublicSchools\Territory;

use Scholaris\Modules\Apps\PublicSchools\Territory\Application\BirthActAggregates;
use Scholaris\Modules\Apps\PublicSchools\Territory\Application\EnrolmentAggregates;
use Scholaris\Modules\Apps\PublicSchools\Territory\Application\TeacherAggregates;
use Scholaris\Modules\Socle\Identity\Contracts\Scope;
use Scholaris\Modules\Socle\Reporting\Application\TerritoryAggregator;
use Tests\Support\PublicSchools\PublicSchoolsTestCase;

/**
 * T654 : fournisseurs d'agrégats territoriaux des écoles publiques (effectifs par niveau et sexe, enseignants, actes de naissance) ;
 * effectifs seulement, masqués sous le seuil pour les rôles « agrégats seuls » (TST-10, SC-05).
 */
final class TerritoryAggregatesTest extends PublicSchoolsTestCase
{
    protected function setUp(): void
    {
        $this->bootPublic();
        $this->actor('partner', 'p-partner', ['reporting.territory_aggregates.read'], Scope::territory('reg-1', 'pub_technical_partner', 't1', 'e1'));
    }

    public function testLesFournisseursRendentDesEffectifsSansNom(): void
    {
        $enrolment = $this->container()->get(EnrolmentAggregates::class)->countsFor('t1', 'e1', self::DAY);
        $teachers = $this->container()->get(TeacherAggregates::class)->countsFor('t1', 'e1', self::DAY);
        $acts = $this->container()->get(BirthActAggregates::class)->countsFor('t1', 'e1', self::DAY);

        self::assertSame(['level=CE1;sex=M' => 2, 'level=CP;sex=M' => 3], $enrolment);
        self::assertSame(['staff=teachers' => 3], $teachers);
        self::assertSame(['act=missing' => 5], $acts);
        self::assertSame([], $this->container()->get(EnrolmentAggregates::class)->countsFor('t1', 'e1', '2027-08-15'), 'Hors année scolaire : aucun effectif inventé.');
    }

    public function testLeTableauTerritorialMasqueLesPetitesCasesPourUnPartenaire(): void
    {
        $this->container()->get(TerritoryAggregator::class)->run(self::DAY, 't1');
        [$response, $json] = $this->api('GET', '/dashboards/territories/reg-1', 'partner');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $indicators = array_column($json['data']['indicators'], null, 'key');
        self::assertArrayHasKey('public_schools.enrolment', $indicators);
        foreach ($indicators['public_schools.enrolment']['cells'] as $cell) {
            self::assertTrue($cell['suppressed'], 'TST-10 : une case de moins de 5 élèves est masquée.');
        }
        self::assertStringNotContainsString('Foka', (string) $response->getBody());
    }
}
