<?php

declare(strict_types=1);

namespace Tests\Isolation;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Apps\Higher\Support\Perimeter;
use Scholaris\Modules\Apps\Higher\Support\ScopedBySchool;
use Scholaris\Modules\Socle\Identity\Contracts\Scope;
use Tests\Fixtures\Higher\HigherOrgFixture as Org;
use Tests\Support\Higher\HigherTestCase;

/**
 * Isolation par périmètre dans l'application Supérieur (RG-ORG-2, RG-ORG-3, TST-03, TST-12) : autre client, autre école,
 * autre département, autre filière, enseignant non affecté. Le filtre de ScopedBySchool est appliqué à une vraie requête SQL
 * (table hi_* fictive en SQLite, bornée au client et à l'établissement comme le feront les dépôts Higher).
 */
final class HigherScopeTest extends HigherTestCase
{
    private const PERMISSION = 'higher.enrolment.read';

    private PDO $pdo;
    private ScopedBySchool $scoped;

    protected function setUp(): void
    {
        $this->bootHigher();
        $this->scoped = $this->service(ScopedBySchool::class);
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('CREATE TABLE hi_fixture_records (id CHAR(26) NOT NULL PRIMARY KEY, tenant_id VARCHAR(40) NOT NULL, establishment_id VARCHAR(40) NOT NULL,
            school_id CHAR(26) NULL, department_id CHAR(26) NULL, program_id CHAR(26) NULL, level_id CHAR(26) NULL, group_ref CHAR(26) NULL)');
        $insert = $this->pdo->prepare('INSERT INTO hi_fixture_records VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        foreach (Org::records() as $id => $row) {
            $insert->execute([$id, Org::TENANT_A, Org::ESTABLISHMENT_A, ...$row]);
            $insert->execute([$id . '-b', Org::TENANT_B, Org::ESTABLISHMENT_B, ...$row]);
        }
    }

    protected function tearDown(): void
    {
        unset($this->pdo, $this->scoped);
        parent::tearDown();
    }

    /** @return array<string, array{0: string, 1: ?string, 2: list<string>}> */
    public static function assignments(): array
    {
        return [
            'tout l\'établissement' => [Scope::ESTABLISHMENT, null, ['r-cf', 'r-cf-g1', 'r-cf-g2', 'r-dr', 'r-ec', 'r-info']],
            'école EC' => [Scope::ORG_UNIT, 'EC', ['r-cf', 'r-cf-g1', 'r-cf-g2', 'r-dr', 'r-ec']],
            'école IT' => [Scope::ORG_UNIT, 'IT', ['r-info']],
            'département EC-CF' => [Scope::ORG_UNIT, 'EC-CF', ['r-cf', 'r-cf-g1', 'r-cf-g2']],
            'filière DR' => [Scope::STRUCTURE, 'DR', ['r-dr']],
            'filière CF' => [Scope::STRUCTURE, 'CF', ['r-cf', 'r-cf-g1', 'r-cf-g2']],
            'groupe CF-L1-G1' => [Scope::CLASS_GROUP, 'CF-L1-G1', ['r-cf-g1']],
        ];
    }

    /** @param list<string> $expected */
    #[DataProvider('assignments')]
    public function testChaquePerimetreNeVoitQueSesLignes(string $type, ?string $scopeId, array $expected): void
    {
        $this->assign(Org::TENANT_A, 'u', Org::ESTABLISHMENT_A, self::PERMISSION, $type, $scopeId);

        self::assertSame($expected, $this->visible($this->scoped->perimeter($this->principal('u'), self::PERMISSION, Org::ESTABLISHMENT_A)));
    }

    public function testUnEnseignantNonAffecteNeVoitRien(): void
    {
        $this->access->grant('prof', Org::ESTABLISHMENT_A);
        $perimeter = $this->scoped->perimeter($this->principal('prof'), self::PERMISSION, Org::ESTABLISHMENT_A);

        self::assertTrue($perimeter->isEmpty());
        self::assertSame([], $this->visible($perimeter));
    }

    public function testUnePermissionSansPerimetreOuUnPerimetreSansPermissionNeDonnentRien(): void
    {
        $this->scopes->add(Org::TENANT_A, 'sans-droit', Org::ESTABLISHMENT_A, self::PERMISSION, new Scope(Scope::ESTABLISHMENT, Org::ESTABLISHMENT_A, 'x'));
        self::assertSame([], $this->visible($this->scoped->perimeter($this->principal('sans-droit'), self::PERMISSION, Org::ESTABLISHMENT_A)));

        $this->assign(Org::TENANT_A, 'autre-droit', Org::ESTABLISHMENT_A, 'higher.grades.read', Scope::ESTABLISHMENT);
        self::assertSame([], $this->visible($this->scoped->perimeter($this->principal('autre-droit'), self::PERMISSION, Org::ESTABLISHMENT_A)));
    }

    public function testUnUtilisateurDUnAutreClientNeVoitRienDuClientA(): void
    {
        // Même code d'école « EC » chez le client B : son affectation ne vaut que chez B.
        $this->assign(Org::TENANT_B, 'dir-b', Org::ESTABLISHMENT_B, self::PERMISSION, Scope::ORG_UNIT, 'EC');

        $inA = $this->scoped->perimeter($this->principal('dir-b', Org::TENANT_B), self::PERMISSION, Org::ESTABLISHMENT_A);
        $inB = $this->scoped->perimeter($this->principal('dir-b', Org::TENANT_B), self::PERMISSION, Org::ESTABLISHMENT_B);

        self::assertSame([], $this->visible($inA));
        self::assertSame(['r-cf-b', 'r-cf-g1-b', 'r-cf-g2-b', 'r-dr-b', 'r-ec-b'], $this->visible($inB, Org::TENANT_B, Org::ESTABLISHMENT_B));
    }

    public function testLeSelecteurDEcoleRestreintEtCacheLesEcolesHorsPerimetre(): void
    {
        $this->assign(Org::TENANT_A, 'deux', Org::ESTABLISHMENT_A, self::PERMISSION, Scope::ORG_UNIT, 'EC-CF');
        $this->assign(Org::TENANT_A, 'deux', Org::ESTABLISHMENT_A, self::PERMISSION, Scope::ORG_UNIT, 'IT');
        $principal = $this->principal('deux');

        $all = $this->scoped->perimeter($principal, self::PERMISSION, Org::ESTABLISHMENT_A, ScopedBySchool::ALL_SCHOOLS);
        self::assertSame(['EC', 'IT'], $all->schoolIds);
        self::assertTrue($all->showsSchoolSelector(), 'Plusieurs écoles : sélecteur avec « Toutes les écoles »');
        self::assertSame(['r-cf', 'r-cf-g1', 'r-cf-g2', 'r-info'], $this->visible($all));
        self::assertSame(['r-info'], $this->visible($this->scoped->perimeter($principal, self::PERMISSION, Org::ESTABLISHMENT_A, 'IT')));
        self::assertSame(['r-cf', 'r-cf-g1', 'r-cf-g2'], $this->visible($this->scoped->perimeter($principal, self::PERMISSION, Org::ESTABLISHMENT_A, 'EC')), 'École EC : son seul département EC-CF');

        $this->assign(Org::TENANT_A, 'une', Org::ESTABLISHMENT_A, self::PERMISSION, Scope::ORG_UNIT, 'IT');
        $one = $this->scoped->perimeter($this->principal('une'), self::PERMISSION, Org::ESTABLISHMENT_A);
        self::assertFalse($one->showsSchoolSelector(), 'Une seule école : pas de sélecteur');
        $this->expectNotFound(fn () => $this->scoped->perimeter($this->principal('une'), self::PERMISSION, Org::ESTABLISHMENT_A, 'EC'));
        $this->expectNotFound(fn () => $this->scoped->perimeter($this->principal('une'), self::PERMISSION, Org::ESTABLISHMENT_A, 'IT-INFO'));
    }

    public function testLeDirecteurGeneralChoisitNImporteQuelleEcoleMaisPasUnDepartement(): void
    {
        $this->assign(Org::TENANT_A, 'dg', Org::ESTABLISHMENT_A, self::PERMISSION, Scope::ESTABLISHMENT);

        self::assertSame(['r-info'], $this->visible($this->scoped->perimeter($this->principal('dg'), self::PERMISSION, Org::ESTABLISHMENT_A, 'IT')));
        $this->expectNotFound(fn () => $this->scoped->perimeter($this->principal('dg'), self::PERMISSION, Org::ESTABLISHMENT_A, 'EC-CF'));
        $this->expectNotFound(fn () => $this->scoped->perimeter($this->principal('dg'), self::PERMISSION, Org::ESTABLISHMENT_A, 'inconnue'));
    }

    public function testHorsPerimetreLaLectureRepond404EtLEcriture403Journalise(): void
    {
        $this->assign(Org::TENANT_A, 'chef', Org::ESTABLISHMENT_A, self::PERMISSION, Scope::ORG_UNIT, 'EC-CF');
        $principal = $this->principal('chef');
        $perimeter = $this->scoped->perimeter($principal, self::PERMISSION, Org::ESTABLISHMENT_A);
        $other = ['school' => 'EC', 'department' => 'EC-DR', 'program' => 'DR'];

        $this->scoped->assertReadable($perimeter, ['school' => 'EC', 'department' => 'EC-CF']);
        $this->scoped->assertWritable($principal, $perimeter, ['program' => 'CF'], 'enrolment', 'x');
        $this->expectNotFound(fn () => $this->scoped->assertReadable($perimeter, $other));
        try {
            $this->scoped->assertWritable($principal, $perimeter, $other, 'enrolment', 'r-dr');
            self::fail('Écriture hors périmètre acceptée.');
        } catch (HttpException $e) {
            self::assertSame(403, $e->status);
        }
        self::assertSame('higher.scope.denied', $this->audit->entries[0]['action'] ?? null);
        self::assertSame('r-dr', $this->audit->entries[0]['id']);
    }

    public function testLeProfilHigherDonneRolesEtEcolesPourLeSelecteur(): void
    {
        $this->assign(Org::TENANT_A, 'dir', Org::ESTABLISHMENT_A, 'higher.access', Scope::ORG_UNIT, 'EC', 'higher_dir_ecole');
        $this->assign(Org::TENANT_A, 'dir', Org::ESTABLISHMENT_A, 'higher.access', Scope::STRUCTURE, 'INFO', 'higher_resp_filiere');

        [$response, $json] = $this->api('GET', '/higher/me', 'dir', Org::TENANT_A, Org::ESTABLISHMENT_A);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['higher_dir_ecole', 'higher_resp_filiere'], $json['data']['roles']);
        self::assertSame(['EC', 'IT'], $json['data']['school_ids']);
        self::assertFalse($json['data']['all_schools']);
        self::assertTrue($json['data']['school_selector']);

        $this->access->grant('etranger', Org::ESTABLISHMENT_A);
        self::assertSame(403, $this->api('GET', '/higher/me', 'etranger', Org::TENANT_A, Org::ESTABLISHMENT_A)[0]->getStatusCode());
        self::assertSame(403, $this->api('GET', '/higher/me', 'dir', Org::TENANT_B, Org::ESTABLISHMENT_A)[0]->getStatusCode(), 'Autre client : établissement refusé');
    }

    /** @return list<string> identifiants visibles, triés */
    private function visible(Perimeter $perimeter, string $tenantId = Org::TENANT_A, string $establishmentId = Org::ESTABLISHMENT_A): array
    {
        [$where, $params] = $this->scoped->sqlFilter($perimeter, [
            'school' => 'r.school_id', 'department' => 'r.department_id', 'program' => 'r.program_id', 'level' => 'r.level_id', 'group' => 'r.group_ref',
        ]);
        $statement = $this->pdo->prepare('SELECT r.id FROM hi_fixture_records r WHERE r.tenant_id = ? AND r.establishment_id = ? AND ' . $where . ' ORDER BY r.id');
        $statement->execute([$tenantId, $establishmentId, ...$params]);

        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    private function expectNotFound(callable $action): void
    {
        try {
            $action();
            self::fail('404 attendu.');
        } catch (HttpException $e) {
            self::assertSame(404, $e->status);
        }
    }
}
