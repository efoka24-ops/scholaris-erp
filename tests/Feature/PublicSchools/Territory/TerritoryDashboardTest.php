<?php

declare(strict_types=1);

namespace Tests\Feature\PublicSchools\Territory;

use Scholaris\Modules\Socle\Identity\Contracts\Scope;
use Tests\Support\PublicSchools\PublicPrimaryTestCase;

/**
 * T690 : tableau de bord territorial (API-12, DOC-28) : indicateurs dérivés du CDC sur les agrégats masqués du socle (une case de moins
 * de 5 reste masquée, TST-10), accès selon le territoire (inspecteur : son arrondissement ; délégué : son département ; cabinet :
 * tout), descente vers les enfants, DOC-28, SC-07 (département de 500 écoles simulées servi en moins de 3 s). Données fictives.
 */
final class TerritoryDashboardTest extends PublicPrimaryTestCase
{
    protected function setUp(): void
    {
        $this->bootPrimary();
        $this->permissions->grant('inspector', 'public_schools.aggregates.read');
        $this->actor('delegate', 'p-delegate', ['public_schools.aggregates.read', 'documents.read'], null);
        $this->scopes->give('delegate', Scope::territory('dep-1', 'pub_departmental_delegate', 't1', 'e1'), Scope::territory('dep-1', 'pub_departmental_delegate', 't1', 'e2'));
        $this->people->add('t1', 'p-delegate', 'Didier', 'Elegue');
        $this->actor('cabinet', 'p-cabinet', ['public_schools.aggregates.read'], new Scope(Scope::ESTABLISHMENT, 'e1', 'pub_cabinet'));
        foreach (['arr-1', 'dep-1'] as $territory) {
            $this->seed($territory, [
                'public_schools.enrolment' => ['level=CE1;sex=F' => 120, 'level=CE1;sex=M' => 100],
                'public_schools.teachers' => ['staff=teachers' => 8],
                'public_schools.birth_act' => ['act=missing' => 3, 'act=verified' => 217],
                'public_primary.exam_results' => ['exam=cep;result=passed' => 30, 'exam=cep;result=failed' => 10],
                'public_schools.inspection_headcount' => ['measure=counted' => 98, 'measure=registry' => 100, 'measure=visits' => 6],
            ]);
        }
    }

    public function testLesIndicateursDerivesRespectentLeMasquageEtLePerimetre(): void
    {
        [$response, $json] = $this->api('GET', '/public/dashboards/arr-1', 'inspector');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $derived = array_column($json['data']['derived'], null, 'code');
        self::assertSame(220, $derived['pupils']['value']);
        self::assertEquals(1.2, $derived['parity_index']['value']);
        self::assertEquals(27.5, $derived['pupils_per_teacher']['value']);
        self::assertEquals(75.0, $derived['exam_success_rate']['value'], 'Admis ÷ présents.');
        self::assertEquals(2.0, $derived['headcount_gap_rate']['value'], 'O1.');
        self::assertTrue($derived['birth_act_missing_rate']['masked'], 'Une case de 3 élèves reste masquée (TST-10).');
        self::assertNull($derived['birth_act_missing_rate']['value']);
        self::assertStringNotContainsString('"person_id"', (string) $response->getBody());

        [$far] = $this->api('GET', '/public/dashboards/dep-1', 'inspector');
        self::assertSame(403, $far->getStatusCode(), 'L\'inspecteur ne voit que son arrondissement.');
        [$dep] = $this->api('GET', '/public/dashboards/dep-1', 'delegate');
        self::assertSame(200, $dep->getStatusCode());
        [$region] = $this->api('GET', '/public/dashboards/reg-1', 'cabinet');
        self::assertSame(200, $region->getStatusCode(), 'Rôle national : tout territoire.');
        [$school] = $this->api('GET', '/public/dashboards/arr-1', 'director');
        self::assertSame(403, $school->getStatusCode());
        [$unknown] = $this->api('GET', '/public/dashboards/nowhere', 'cabinet');
        self::assertSame(404, $unknown->getStatusCode());

        [, $children] = $this->api('GET', '/public/dashboards/dep-1/children', 'delegate');
        self::assertSame(['arr-1', 'arr-2'], array_column($children['data']['territories'], 'id'));
        [, $entry] = $this->api('GET', '/public/dashboards/entry', 'inspector');
        self::assertSame(['arr-1'], array_column($entry['data']['territories'], 'id'));
        [$doc] = $this->api('POST', '/public/dashboards/dep-1/document', 'delegate', []);
        self::assertSame(201, $doc->getStatusCode(), (string) $doc->getBody());
    }

    public function testUnDepartementDeCinqCentsEcolesEstServiEnMoinsDeTroisSecondes(): void
    {
        for ($i = 1; $i <= 500; ++$i) {
            $this->territories->attach('t1', sprintf('syn-%03d', $i), 'arr-3');
        }
        $cells = [];
        foreach (['SIL', 'CP', 'CE1', 'CE2', 'CM1', 'CM2'] as $level) {
            $cells['public_schools.enrolment']['level=' . $level . ';sex=F'] = 9000;
            $cells['public_schools.enrolment']['level=' . $level . ';sex=M'] = 9100;
        }
        $cells['public_schools.teachers'] = ['staff=teachers' => 2100];
        $cells['public_primary.exam_results'] = ['exam=cep;result=passed' => 7000, 'exam=cep;result=failed' => 2000, 'exam=cep;result=absent' => 300];
        $this->seed('dep-2', $cells, 500);

        $start = microtime(true);
        [$response, $json] = $this->api('GET', '/public/dashboards/dep-2', 'cabinet');
        $elapsed = microtime(true) - $start;
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(500, $json['data']['indicators'][0]['establishment_count']);
        self::assertLessThan(3.0, $elapsed, 'SC-07 / NF-P3 : agrégats d\'un département en moins de 3 s.');
    }

    /** @param array<string, array<string, int>> $indicators */
    private function seed(string $territory, array $indicators, int $schools = 1): void
    {
        $pdo = $this->connections()->pdo();
        foreach ($indicators as $key => $cells) {
            foreach ($cells as $cell => $count) {
                $pdo->prepare('INSERT INTO reporting_territory_aggregates (id, tenant_id, territory_id, indicator_key, reference_date, cell_key, cell_count, establishment_count, computed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([self::ulid(), 't1', $territory, $key, '2026-10-07', $cell, $count, $schools, '2026-10-08T03:00:00+01:00']);
            }
        }
    }
}
