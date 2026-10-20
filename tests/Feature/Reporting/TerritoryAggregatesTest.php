<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use Scholaris\Modules\Socle\Organisation\Contracts\TerritoryDirectory;
use Scholaris\Modules\Socle\Organisation\Contracts\TerritoryInfo;
use Scholaris\Modules\Socle\Reporting\Application\ThresholdDisclosurePolicy;
use Scholaris\Modules\Socle\Reporting\Console\AggregateTerritoriesCommand;
use Scholaris\Modules\Socle\Reporting\Contracts\TerritoryAggregateProvider;
use Tests\Support\Reporting\ReportingTestCase;

/**
 * PP4 (T613) : agrégats par territoire, masquage des petits effectifs (seuil paramétré à 5, TST-10) et périmètre de lecture.
 * Région R : arrondissement A (écoles e1, e2), arrondissement B (école e3). Client t2 : arrondissement X (école x1).
 */
final class TerritoryAggregatesTest extends ReportingTestCase
{
    protected function setUp(): void
    {
        FakeEnrolment::$counts = [
            'e1' => ['sex=F' => 2, 'sex=M' => 1],
            'e2' => ['sex=F' => 10, 'sex=M' => 1],
            'e3' => ['sex=F' => 30, 'sex=M' => 28],
            'x1' => ['sex=F' => 50, 'sex=M' => 50],
        ];
        $this->bootReporting([
            'reporting.territory_aggregates.test' => [FakeEnrolment::class],
            TerritoryDirectory::class => new FakeTerritories(),
        ]);
        $this->establishments->add('t1', 'e3', 'École publique de B');
        $this->permissions->grant('partner', 'reporting.territory_aggregates.read');
        $this->permissions->grant('inspector-a', 'reporting.territory_aggregates.read', 'e1');
        $this->permissions->grant('inspector-a', 'reporting.territory_aggregates.read', 'e2');
        $this->permissions->grant('nobody', 'reporting.read');
    }

    public function testLaPolitiqueMasqueSousLeSeuilEtUneCaseVoisine(): void
    {
        $policy = new ThresholdDisclosurePolicy();

        self::assertTrue($policy->suppressPrimary(3, 5));
        self::assertTrue($policy->suppressPrimary(4, 5), '4 masqué');
        self::assertFalse($policy->suppressPrimary(5, 5), '5 affiché');
        self::assertFalse($policy->suppressPrimary(0, 5), 'un zéro ne désigne personne');
        self::assertSame(['b'], $policy->suppressSecondary(['a' => 3, 'b' => 7, 'c' => 9], ['a'], 5), 'la plus petite voisine');
        self::assertSame([], $policy->suppressSecondary(['a' => 3, 'b' => 4, 'c' => 9], ['a', 'b'], 5), 'deux cases masquées dont la somme atteint le seuil');
        self::assertSame(['c'], $policy->suppressSecondary(['a' => 1, 'b' => 2, 'c' => 9, 'd' => 12], ['a', 'b'], 5), 'somme masquée sous le seuil');
        self::assertSame([], $policy->suppressSecondary(['a' => 3, 'z' => 0], ['a'], 5), 'aucune voisine non nulle');
    }

    public function testLaCommandeEstEnregistreeEtAgregeDansLeTerritoireEtSesParents(): void
    {
        $command = $this->container()->get('console.command.' . AggregateTerritoriesCommand::NAME);
        self::assertInstanceOf(AggregateTerritoriesCommand::class, $command);
        ob_start();
        self::assertSame(0, $command->run(['2026-10-08', 't1']));
        ob_end_clean();
        self::assertSame(2, $command->run(['--inconnu']));

        $pdo = $this->container()->get(\Scholaris\Core\Database\ConnectionFactory::class)->pdo();
        $rows = $pdo->query("SELECT territory_id, cell_key, cell_count, establishment_count FROM reporting_territory_aggregates WHERE tenant_id = 't1' ORDER BY territory_id, cell_key")->fetchAll(\PDO::FETCH_NUM);
        self::assertEquals([
            ['A', 'sex=F', 12, 2], ['A', 'sex=M', 2, 2],
            ['B', 'sex=F', 30, 1], ['B', 'sex=M', 28, 1],
            ['R', 'sex=F', 42, 3], ['R', 'sex=M', 30, 3],
        ], $rows);

        ob_start();
        $command->run(['2026-10-08', 't1']);
        ob_end_clean();
        self::assertSame(6, (int) $pdo->query("SELECT COUNT(*) FROM reporting_territory_aggregates WHERE tenant_id = 't1'")->fetchColumn(), 'idempotent');
    }

    public function testUnAgregatDeTroisElevesEstMasquePourLePartenaire(): void
    {
        FakeEnrolment::$counts['e1'] = ['total' => 3];
        FakeEnrolment::$counts['e2'] = ['total' => 0];
        $this->aggregate();

        [$response, $json] = $this->api('/dashboards/territories/A', 'partner');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('2026-10-08', $json['data']['reference_date']);
        self::assertSame(5, $json['data']['minimum_cell_size']);
        self::assertSame([['key' => 'total', 'value' => null, 'suppressed' => true]], $json['data']['indicators'][0]['cells'], 'TST-10');
        self::assertStringNotContainsString('"value":3', (string) $response->getBody());
    }

    public function testQuatreEstMasqueAvecUneVoisineEtCinqAffiche(): void
    {
        FakeEnrolment::$counts['e1'] = ['total' => 4];
        FakeEnrolment::$counts['e2'] = ['total' => 0];
        FakeEnrolment::$counts['e3'] = ['total' => 5];
        $this->aggregate();

        self::assertSame([['key' => 'total', 'value' => null, 'suppressed' => true]], $this->api('/dashboards/territories/A', 'partner')[1]['data']['indicators'][0]['cells'], '4 masqué');
        self::assertSame([['key' => 'total', 'value' => 5, 'suppressed' => false]], $this->api('/dashboards/territories/B', 'partner')[1]['data']['indicators'][0]['cells'], '5 affiché');

        FakeEnrolment::$counts['e1'] = ['sex=F' => 2, 'sex=M' => 4];
        FakeEnrolment::$counts['e2'] = ['sex=F' => 3, 'sex=M' => 2, 'sex=X' => 2];
        $this->aggregate();
        $cells = $this->api('/dashboards/territories/A', 'partner')[1]['data']['indicators'][0]['cells'];
        self::assertSame([null, 6, null], array_column($cells, 'value'), 'X=2 masqué ; F=5, plus petite voisine, masquée à son tour ; M=6 affiché');
    }

    public function testLePerimetreDeLecture(): void
    {
        $this->aggregate();

        self::assertSame(200, $this->api('/dashboards/territories/A', 'inspector-a')[0]->getStatusCode(), 'inspecteur de A');
        self::assertSame(403, $this->api('/dashboards/territories/B', 'inspector-a')[0]->getStatusCode(), 'pas B');
        self::assertSame(403, $this->api('/dashboards/territories/R', 'inspector-a')[0]->getStatusCode(), 'ni la région');
        self::assertSame(403, $this->api('/dashboards/territories/A', 'nobody')[0]->getStatusCode());
        self::assertSame(404, $this->api('/dashboards/territories/X', 'partner')[0]->getStatusCode(), 'territoire d\'un autre client');
        self::assertSame(404, $this->api('/dashboards/territories/inconnu', 'partner')[0]->getStatusCode());
        self::assertSame(422, $this->api('/dashboards/territories/A?date=hier', 'partner')[0]->getStatusCode());
        $empty = $this->api('/dashboards/territories/A?date=2020-01-01', 'partner')[1]['data'];
        self::assertSame([], $empty['indicators']);
    }

    private function aggregate(): void
    {
        ob_start();
        $this->container()->get('console.command.' . AggregateTerritoriesCommand::NAME)->run(['2026-10-08', 't1']);
        ob_end_clean();
    }
}

final class FakeEnrolment implements TerritoryAggregateProvider
{
    /** @var array<string, array<string, int>> */
    public static array $counts = [];

    public function indicator(): string
    {
        return 'test.enrolment';
    }

    public function countsFor(string $tenantId, string $establishmentId, string $referenceDate): array
    {
        return self::$counts[$establishmentId] ?? [];
    }
}

final class FakeTerritories implements TerritoryDirectory
{
    /** @var array<string, array{0: string, 1: ?string}> id => [client, parent] */
    private const TREE = ['R' => ['t1', null], 'A' => ['t1', 'R'], 'B' => ['t1', 'R'], 'X' => ['t2', null]];
    /** @var array<string, string> */
    private const LINKS = ['e1' => 'A', 'e2' => 'A', 'e3' => 'B', 'x1' => 'X'];

    public function find(string $tenantId, string $territoryId, string $at): ?TerritoryInfo
    {
        $node = self::TREE[$territoryId] ?? null;

        return $node === null || $node[0] !== $tenantId ? null : new TerritoryInfo($tenantId, $territoryId, 'arrondissement', $territoryId, $territoryId, $node[1], '2026-01-01', null, '1', $territoryId);
    }

    public function lineage(string $tenantId, string $territoryId, string $at): array
    {
        $out = [];
        for ($id = $territoryId; $id !== null && ($info = $this->find($tenantId, $id, $at)) !== null; $id = $info->parentId) {
            $out[] = $info;
        }

        return $out;
    }

    public function descendantsOf(string $tenantId, string $territoryId, string $at): array
    {
        $out = [];
        foreach (array_keys(self::TREE) as $id) {
            foreach ($this->lineage($tenantId, $id, $at) as $ancestor) {
                if ($ancestor->id === $territoryId) {
                    $out[] = $this->find($tenantId, $id, $at);
                }
            }
        }

        return array_values(array_filter($out));
    }

    public function territoryOf(string $tenantId, string $establishmentId, string $at): ?TerritoryInfo
    {
        return isset(self::LINKS[$establishmentId]) ? $this->find($tenantId, self::LINKS[$establishmentId], $at) : null;
    }

    public function establishmentsIn(string $tenantId, string $territoryId, string $at): array
    {
        $ids = array_map(static fn (TerritoryInfo $t): string => $t->id, $this->descendantsOf($tenantId, $territoryId, $at));
        $found = array_keys(array_filter(self::LINKS, static fn (string $t): bool => in_array($t, $ids, true)));
        sort($found);

        return $found;
    }
}
