<?php

declare(strict_types=1);

namespace Tests\Isolation;

use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\PublicSchools\Policies\ScopeResolver;
use Scholaris\Modules\Apps\PublicSchools\Support\Perimeter;
use Scholaris\Modules\Apps\PublicSchools\Support\ScopedByTerritory;
use Scholaris\Modules\Socle\Identity\Contracts\Scope;
use Tests\Support\Higher\FakePermissionScopes;
use Tests\Support\Higher\HigherPermissions;
use Tests\Support\Higher\InMemoryOrgUnitTree;
use Tests\Support\Higher\RecordingAuditLog;
use Tests\Support\PublicSchools\InMemoryTerritories;
use Tests\Support\PublicSchools\RecordingNamedAccessJournal;

/**
 * Isolation territoriale des écoles publiques (RG-TER-2, TST-05) : autre client, autre arrondissement, autre classe, région.
 * Le filtre de ScopedByTerritory est appliqué à une vraie requête SQL (table pub_* fictive en SQLite, bornée au client et à l'école
 * comme le feront les dépôts). Données fictives : territoires SYN-*, écoles E1..E4.
 *
 * Client A : région R1 > département D1 > arrondissements A1 (écoles E1, E2) et A2 (école E3). Client B : région RB > DB > AB (école E4).
 */
final class PublicTerritoryTest extends TestCase
{
    private const NAMED = 'public_schools.pupils.named.read';
    private const READ = 'public_schools.pupils.read';
    private const AT = '2026-10-20T10:00:00+01:00';

    private HigherPermissions $permissions;
    private FakePermissionScopes $scopes;
    private InMemoryTerritories $territories;
    private RecordingNamedAccessJournal $journal;
    private RecordingAuditLog $audit;
    private ScopedByTerritory $scoped;
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->permissions = new HigherPermissions();
        $this->scopes = new FakePermissionScopes();
        $this->territories = new InMemoryTerritories();
        $this->journal = new RecordingNamedAccessJournal();
        $this->audit = new RecordingAuditLog();
        $tree = new InMemoryOrgUnitTree();
        foreach (['E1', 'E2', 'E3'] as $establishment) {
            $tree->addStructure('client-a', $establishment, $establishment . '-CE1', 'class', null, null);
            $tree->addStructure('client-a', $establishment, $establishment . '-CE2', 'class', null, null);
        }
        $this->territories->add('client-a', 'R1', 'region', null);
        $this->territories->add('client-a', 'D1', 'departement', 'R1');
        $this->territories->add('client-a', 'A1', 'arrondissement', 'D1');
        $this->territories->add('client-a', 'A2', 'arrondissement', 'D1');
        $this->territories->attach('client-a', 'E1', 'A1');
        $this->territories->attach('client-a', 'E2', 'A1');
        $this->territories->attach('client-a', 'E3', 'A2');
        $this->territories->add('client-b', 'RB', 'region', null);
        $this->territories->add('client-b', 'DB', 'departement', 'RB');
        $this->territories->add('client-b', 'AB', 'arrondissement', 'DB');
        $this->territories->attach('client-b', 'E4', 'AB');

        $resolver = new ScopeResolver($this->permissions, $this->scopes, $tree, $this->territories);
        $this->scoped = new ScopedByTerritory($resolver, $this->territories, $this->journal, $this->audit);

        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('CREATE TABLE pub_fixture_pupils (id CHAR(26) NOT NULL PRIMARY KEY, tenant_id VARCHAR(40) NOT NULL, establishment_id VARCHAR(40) NOT NULL, class_id CHAR(26) NOT NULL)');
        $insert = $this->pdo->prepare('INSERT INTO pub_fixture_pupils VALUES (?, ?, ?, ?)');
        foreach (['E1', 'E2', 'E3'] as $establishment) {
            $insert->execute([$establishment . '-p1', 'client-a', $establishment, $establishment . '-CE1']);
            $insert->execute([$establishment . '-p2', 'client-a', $establishment, $establishment . '-CE2']);
        }
        $insert->execute(['E4-p1', 'client-b', 'E4', 'E4-CE1']);
    }

    protected function tearDown(): void
    {
        unset($this->pdo, $this->scoped, $this->permissions, $this->scopes, $this->territories, $this->journal, $this->audit);
        parent::tearDown();
    }

    private function grant(string $tenant, string $user, string $establishment, string $permission, Scope $scope): void
    {
        $this->permissions->grant($tenant, $user, $permission, $establishment);
        $this->scopes->add($tenant, $user, $establishment, $permission, $scope);
    }

    /** @return list<string> */
    private function visible(Perimeter $perimeter, string $tenant, string $establishment): array
    {
        [$where, $params] = $this->scoped->sqlFilter($perimeter, ['class' => 'p.class_id']);
        $statement = $this->pdo->prepare('SELECT p.id FROM pub_fixture_pupils p WHERE p.tenant_id = ? AND p.establishment_id = ? AND ' . $where . ' ORDER BY p.id');
        $statement->execute([$tenant, $establishment, ...$params]);

        return array_values(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)));
    }

    private function httpStatus(callable $action): int
    {
        try {
            $action();
        } catch (HttpException $e) {
            return $e->status;
        }

        return 200;
    }

    public function testUnAutreClientNeVoitRienEtReçoit404(): void
    {
        // Un directeur du client B, même avec des droits chez lui, n'a aucun périmètre dans une école du client A.
        $this->grant('client-b', 'dir-b', 'E4', self::READ, new Scope(Scope::ESTABLISHMENT, 'E4', 'pub_school_director'));
        $principal = new Principal('dir-b', 'client-b');

        $inA = $this->scoped->perimeter($principal, self::READ, 'E1');
        self::assertTrue($inA->isEmpty());
        self::assertSame([], $this->visible($inA, 'client-b', 'E1'));
        self::assertSame([], $this->visible($inA, 'client-a', 'E1'), 'Périmètre vide : aucune ligne, même avec le bon client dans la requête');
        self::assertSame(404, $this->httpStatus(fn () => $this->scoped->assertReadable($inA, 'E1-CE1')));
        self::assertSame(404, $this->httpStatus(fn () => $this->scoped->assertNamedAccess($principal, $inA, 'pupil', 'E1-p1', 'control')));
        self::assertSame(['E4-p1'], $this->visible($this->scoped->perimeter($principal, self::READ, 'E4'), 'client-b', 'E4'));

        // Une affectation territoriale du client B ne vaut rien chez A (le territoire n'existe pas dans A).
        $this->grant('client-b', 'insp-b', 'E1', self::NAMED, Scope::territory('AB', 'pub_district_inspector', 'client-b', 'E1'));
        self::assertTrue($this->scoped->perimeter(new Principal('insp-b', 'client-b'), self::NAMED, 'E1')->isEmpty());
        // Un scope dont le tenant ne correspond pas au principal est ignoré.
        $this->grant('client-a', 'insp-x', 'E1', self::NAMED, Scope::territory('A1', 'pub_district_inspector', 'client-b', 'E1'));
        self::assertTrue($this->scoped->perimeter(new Principal('insp-x', 'client-a'), self::NAMED, 'E1')->isEmpty());
    }

    public function testUnAutreArrondissementNEstPasVisible(): void
    {
        $this->grant('client-a', 'insp-a1', 'E1', self::NAMED, Scope::territory('A1', 'pub_district_inspector', 'client-a', 'E1'));
        $this->grant('client-a', 'insp-a1', 'E3', self::NAMED, Scope::territory('A1', 'pub_district_inspector', 'client-a', 'E3'));
        $principal = new Principal('insp-a1', 'client-a');

        $own = $this->scoped->perimeter($principal, self::NAMED, 'E1');
        self::assertSame(['E1-p1', 'E1-p2'], $this->visible($own, 'client-a', 'E1'));
        // E3 relève de A2 : même si une affectation fautive est rendue par Identity, la défense en profondeur la rejette.
        $other = $this->scoped->perimeter($principal, self::NAMED, 'E3');
        self::assertTrue($other->isEmpty());
        self::assertSame([], $this->visible($other, 'client-a', 'E3'));
        self::assertSame(404, $this->httpStatus(fn () => $this->scoped->assertReadable($other, null)));
        // Sans aucun droit sur E2 (même arrondissement mais aucune affectation rendue) : rien.
        self::assertTrue($this->scoped->perimeter($principal, self::NAMED, 'E2')->isEmpty());
    }

    public function testUneAutreClasseNEstPasVisibleAUnTitulaire(): void
    {
        $this->grant('client-a', 'maitre', 'E1', self::READ, new Scope(Scope::CLASS_GROUP, 'E1-CE1', 'pub_class_teacher'));
        $principal = new Principal('maitre', 'client-a');
        $perimeter = $this->scoped->perimeter($principal, self::READ, 'E1');

        self::assertSame(['E1-p1'], $this->visible($perimeter, 'client-a', 'E1'));
        $this->scoped->assertReadable($perimeter, 'E1-CE1');
        self::assertSame(404, $this->httpStatus(fn () => $this->scoped->assertReadable($perimeter, 'E1-CE2')));
        self::assertSame(403, $this->httpStatus(fn () => $this->scoped->assertWritable($principal, $perimeter, 'E1-CE2', 'attendance', 'x1')));
        self::assertSame('public_schools.scope.denied', $this->audit->entries[0]['action'] ?? null);
        self::assertSame('x1', $this->audit->entries[0]['id']);
        // Une autre école du même client : aucun droit.
        self::assertTrue($this->scoped->perimeter($principal, self::READ, 'E2')->isEmpty());

        // Le directeur voit toute son école, pas celle d'à côté.
        $this->grant('client-a', 'dir', 'E1', self::READ, new Scope(Scope::ESTABLISHMENT, 'E1', 'pub_school_director'));
        self::assertSame(['E1-p1', 'E1-p2'], $this->visible($this->scoped->perimeter(new Principal('dir', 'client-a'), self::READ, 'E1'), 'client-a', 'E1'));
        self::assertSame([], $this->visible($this->scoped->perimeter(new Principal('dir', 'client-a'), self::READ, 'E2'), 'client-a', 'E2'));
    }

    public function testRegionEtNationalNeLisentQueDesAgregats(): void
    {
        $this->grant('client-a', 'region', 'E1', self::NAMED, Scope::territory('R1', 'pub_regional_delegate', 'client-a', 'E1'));
        $regional = new Principal('region', 'client-a');
        $perimeter = $this->scoped->perimeter($regional, self::NAMED, 'E1');

        self::assertTrue($perimeter->isTerritorial(), 'La région contient l\'école : périmètre territorial reconnu');
        self::assertFalse($this->scoped->canReadNamed($perimeter));
        self::assertSame(403, $this->httpStatus(fn () => $this->scoped->assertNamedAccess($regional, $perimeter, 'pupil', 'E1-p1', 'control')));
        self::assertSame([], $this->journal->entries, 'Un refus n\'est pas une consultation');

        // Même affectation, mais permission non nominative : toujours refus (aucun nominatif sans permission `*.named.*`).
        $this->grant('client-a', 'dept', 'E1', self::READ, Scope::territory('D1', 'pub_departmental_delegate', 'client-a', 'E1'));
        $department = new Principal('dept', 'client-a');
        $plain = $this->scoped->perimeter($department, self::READ, 'E1');
        self::assertSame(403, $this->httpStatus(fn () => $this->scoped->assertNamedAccess($department, $plain, 'pupil', 'E1-p1', 'transfer')));
    }

    public function testLeNominatifTerritorialExigeNiveauFinaliteEtJournal(): void
    {
        $this->grant('client-a', 'insp', 'E1', self::NAMED, Scope::territory('A1', 'pub_district_inspector', 'client-a', 'E1'));
        $this->grant('client-a', 'dept', 'E3', self::NAMED, Scope::territory('D1', 'pub_departmental_delegate', 'client-a', 'E3'));
        $at = new DateTimeImmutable(self::AT);

        $inspector = new Principal('insp', 'client-a');
        $perimeter = $this->scoped->perimeter($inspector, self::NAMED, 'E1');
        self::assertTrue($this->scoped->canReadNamed($perimeter, $at));
        self::assertSame(422, $this->httpStatus(fn () => $this->scoped->assertNamedAccess($inspector, $perimeter, 'pupil', 'E1-p1', null, $at)));
        self::assertSame(422, $this->httpStatus(fn () => $this->scoped->assertNamedAccess($inspector, $perimeter, 'pupil', 'E1-p1', 'curiosite', $at)));
        self::assertSame([], $this->journal->entries);

        $this->scoped->assertNamedAccess($inspector, $perimeter, 'pupil', 'E1-p1', 'transfer', $at);
        self::assertSame([['tenant' => 'client-a', 'establishment' => 'E1', 'actor' => 'insp', 'type' => 'pupil', 'id' => 'E1-p1', 'purpose' => 'transfer']], $this->journal->entries);

        // Département : niveau suffisant, école du département (E3 relève de A2 < D1).
        $department = new Principal('dept', 'client-a');
        $this->scoped->assertNamedAccess($department, $this->scoped->perimeter($department, self::NAMED, 'E3'), 'pupil', 'E3-p1', 'exam', $at);
        self::assertCount(2, $this->journal->entries);
    }

    public function testExercerDansLEcoleNEstPasUneSupervisionNiJournalisee(): void
    {
        $this->grant('client-a', 'dir', 'E1', self::NAMED, new Scope(Scope::ESTABLISHMENT, 'E1', 'pub_school_director'));
        $principal = new Principal('dir', 'client-a');
        $perimeter = $this->scoped->perimeter($principal, self::NAMED, 'E1');

        $this->scoped->assertNamedAccess($principal, $perimeter, 'pupil', 'E1-p1', null);
        self::assertSame([], $this->journal->entries);
    }
}
