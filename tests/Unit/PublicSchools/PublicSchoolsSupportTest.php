<?php

declare(strict_types=1);

namespace Tests\Unit\PublicSchools;

use PHPUnit\Framework\TestCase;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\RouteRegistry;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\PublicPrimary\PublicPrimaryApplication;
use Scholaris\Modules\Apps\PublicSchools\Policies\RoleMatrix;
use Scholaris\Modules\Apps\PublicSchools\PublicSchoolsApplication;
use Scholaris\Modules\Apps\PublicSchools\Support\GatedAction;
use Scholaris\Modules\Apps\PublicSchools\Support\Perimeter;
use Scholaris\Modules\Apps\PublicSchools\Support\PublicRoutes;
use Scholaris\Modules\Apps\PublicSchools\Support\WriteGate;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationManifest;
use Tests\Support\PublicSchools\FakePublicApplicationAccess;

/** Garde d'écriture, routage gardé, périmètre, manifestes et matrice des rôles des écoles publiques (T620, T621). */
final class PublicSchoolsSupportTest extends TestCase
{
    private FakePublicApplicationAccess $access;
    private WriteGate $gate;

    protected function setUp(): void
    {
        $this->access = new FakePublicApplicationAccess();
        $this->gate = new WriteGate($this->access);
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

    public function testLaPorteSuitLEtatDeLAbonnement(): void
    {
        $tenant = new Principal('u', 't1');
        // Jamais ouverte : ni lecture (404) ni écriture (403).
        self::assertSame(403, $this->httpStatus(fn () => $this->gate->assertWritable($tenant)));
        self::assertSame(404, $this->httpStatus(fn () => $this->gate->assertReadable($tenant)));

        foreach ([ApplicationAccess::TRIAL, ApplicationAccess::ACTIVE, ApplicationAccess::PAST_DUE] as $state) {
            $this->access->set('t1', 'public_primary', $state);
            self::assertSame(200, $this->httpStatus(fn () => $this->gate->assertWritable($tenant)), $state);
        }

        $this->access->set('t1', 'public_primary', ApplicationAccess::SUSPENDED);
        self::assertSame(423, $this->httpStatus(fn () => $this->gate->assertWritable($tenant)));
        self::assertSame(200, $this->httpStatus(fn () => $this->gate->assertReadable($tenant)));
        self::assertFalse($this->gate->allowsWrites('t1'));

        $this->access->set('t1', 'public_primary', ApplicationAccess::CLOSED);
        self::assertSame(403, $this->httpStatus(fn () => $this->gate->assertWritable('t1')));
        self::assertSame(404, $this->httpStatus(fn () => $this->gate->assertReadable('t1')));
    }

    public function testUneRouteCommuneEstOuverteParLUneOuLAutreApplication(): void
    {
        $this->access->set('t1', 'public_nursery', ApplicationAccess::ACTIVE);
        $this->access->set('t1', 'public_primary', ApplicationAccess::SUSPENDED);

        self::assertSame(200, $this->httpStatus(fn () => $this->gate->assertWritable('t1')), 'Commun : la maternelle active suffit');
        self::assertSame(423, $this->httpStatus(fn () => $this->gate->assertWritable('t1', 'public_primary')));
        self::assertSame(200, $this->httpStatus(fn () => $this->gate->assertWritable('t1', 'public_nursery')));
        self::assertSame(403, $this->httpStatus(fn () => $this->gate->assertWritable('t2')), 'Autre client : non ouverte');
        $this->expectException(\InvalidArgumentException::class);
        $this->gate->assertWritable('t1', 'higher');
    }

    public function testLeMessageDeSuspensionEstUneProblemDetailsDeType423(): void
    {
        $this->access->set('t1', 'public_primary', ApplicationAccess::SUSPENDED);
        try {
            $this->gate->assertWritable('t1');
            self::fail('423 attendu');
        } catch (HttpException $e) {
            self::assertSame(423, $e->status);
            self::assertSame(WriteGate::SUSPENDED_TYPE, $e->type);
        }
    }

    public function testToutesLesRoutesPassentParLaPorteEtLeBonPrefixe(): void
    {
        $registry = new RouteRegistry('/api/v1');
        $common = new PublicRoutes($registry);
        $primary = new PublicRoutes($registry, PublicRoutes::PRIMARY_PREFIX);
        $common->get('/pupils', [PublicSchoolsApplication::class, 'manifest']);
        $common->post('/pupils', [PublicSchoolsApplication::class, 'manifest']);
        $primary->patch('/criteria/{id}', [PublicPrimaryApplication::class, 'manifest']);
        $primary->delete('/criteria/{id}', [PublicPrimaryApplication::class, 'manifest']);

        $routes = [];
        foreach ($registry->all() as $route) {
            $routes[$route['method'] . ' ' . $route['path']] = $route['handler'];
            self::assertSame(GatedAction::class, $route['handler'][0]);
        }
        self::assertSame(['GET /api/v1/public/pupils', 'POST /api/v1/public/pupils', 'PATCH /api/v1/public-primary/criteria/{id}', 'DELETE /api/v1/public-primary/criteria/{id}'], array_keys($routes));
        self::assertStringStartsWith('read|', $routes['GET /api/v1/public/pupils'][1]);
        self::assertStringStartsWith('write|', $routes['POST /api/v1/public/pupils'][1]);
        self::assertStringStartsWith('write|', $routes['DELETE /api/v1/public-primary/criteria/{id}'][1]);
    }

    public function testLeRoutageRefuseLesRoutesOuvertesLesCheminsEtLesControleursEtrangers(): void
    {
        $routes = new PublicRoutes(new RouteRegistry('/api/v1'));
        foreach ([
            fn () => $routes->get('/x', [PublicSchoolsApplication::class, 'manifest'], ['public' => true]),
            fn () => $routes->get('/x', [PublicSchoolsApplication::class, 'manifest'], ['auth' => false]),
            fn () => $routes->get('x', [PublicSchoolsApplication::class, 'manifest']),
            fn () => $routes->get('/x', [\stdClass::class, 'manifest']),
            fn () => new PublicRoutes(new RouteRegistry('/api/v1'), '/primary'),
        ] as $case) {
            try {
                $case();
                self::fail('LogicException attendue');
            } catch (\LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testGatedActionExigeLEspaceDeNomsEtDeduitLApplication(): void
    {
        self::assertSame('write|' . PublicSchoolsApplication::class . '|manifest', GatedAction::encode('write', [PublicSchoolsApplication::class, 'manifest']));
        self::assertSame(['read', PublicPrimaryApplication::class, 'manifest'], GatedAction::decode('read|' . PublicPrimaryApplication::class . '|manifest'));
        self::assertSame('public_primary', GatedAction::applicationOf(PublicPrimaryApplication::class));
        self::assertNull(GatedAction::applicationOf(PublicSchoolsApplication::class));
        foreach (['read|stdClass|x', 'delete|' . PublicSchoolsApplication::class . '|manifest', 'read|' . PublicSchoolsApplication::class . '|1x', 'read'] as $bad) {
            try {
                GatedAction::decode($bad);
                self::fail('LogicException attendue : ' . $bad);
            } catch (\LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testLePerimetreRefuseParDefautEtCouvreSelonLaPortee(): void
    {
        $none = Perimeter::none('t', 'e', 'p');
        self::assertTrue($none->isEmpty());
        self::assertFalse($none->coversClass('c1'));

        $teacher = new Perimeter('t', 'e', 'p', false, ['c1']);
        self::assertTrue($teacher->isInSchool());
        self::assertTrue($teacher->coversClass('c1'));
        self::assertFalse($teacher->coversClass('c2'));
        self::assertFalse($teacher->coversClass(null));

        self::assertTrue((new Perimeter('t', 'e', 'p', true))->coversClass('c9'));
        $supervisor = new Perimeter('t', 'e', 'p', false, [], ['A1']);
        self::assertTrue($supervisor->isTerritorial());
        self::assertFalse($supervisor->isInSchool());
        self::assertTrue($supervisor->coversClass('c9'));
    }

    public function testLesManifestesSontComingSoonEtCoherentsAvecLeCatalogue(): void
    {
        foreach ([PublicSchoolsApplication::manifest(), PublicPrimaryApplication::manifest()] as $manifest) {
            self::assertSame(ApplicationManifest::COMING_SOON, $manifest->status);
            self::assertSame('primary', $manifest->target);
        }
        self::assertSame('platform.applications.public_schools', PublicSchoolsApplication::CONTAINER_KEY);
        self::assertSame('platform.applications.public_primary', PublicPrimaryApplication::CONTAINER_KEY);
        self::assertSame(PublicSchoolsApplication::KEY, PublicSchoolsApplication::manifest()->key);

        $definitions = require dirname(__DIR__, 3) . '/src/Modules/Apps/PublicSchools/definitions.php';
        self::assertInstanceOf(ApplicationManifest::class, $definitions['platform.applications.public_schools']);
        $primary = require dirname(__DIR__, 3) . '/src/Modules/Apps/PublicPrimary/definitions.php';
        self::assertInstanceOf(ApplicationManifest::class, $primary['platform.applications.public_primary']);
    }

    public function testLesPermissionsDeclareesSontCelleDeLaMatrice(): void
    {
        $common = PublicSchoolsApplication::permissions();
        self::assertContains('public_schools.pupils.named.read', $common);
        self::assertContains('public_schools.apee.non_payers.read', $common);
        self::assertNotContains('public_primary.exams.write', $common);
        self::assertContains('public_primary.exams.write', PublicPrimaryApplication::permissions());
        self::assertSame($common, array_values(array_unique($common)));
        self::assertCount(17, PublicSchoolsApplication::roles());
        self::assertContains('pub_class_teacher', PublicPrimaryApplication::roles());
        self::assertNotContains('pub_cabinet', PublicPrimaryApplication::roles());
    }

    public function testLaMatriceNeDonneJamaisLeNominatifAuxRolesAAgregatsSeuls(): void
    {
        foreach (['pub_cabinet', 'pub_planning', 'pub_regional_delegate', 'pub_technical_partner'] as $role) {
            self::assertTrue(RoleMatrix::isAggregateOnly($role), $role);
            foreach (RoleMatrix::permissionsOf($role) as $permission) {
                self::assertFalse(RoleMatrix::isNamed($permission), $role . ' ' . $permission);
            }
            self::assertFalse(RoleMatrix::allows($role, 'public_schools.pupils.named.read'));
        }
        self::assertTrue(RoleMatrix::allows('pub_district_inspector', 'public_schools.pupils.named.read'));
        self::assertSame(['pub_departmental_delegate', 'pub_district_inspector'], RoleMatrix::rolesWith('public_schools.pupils.named.read'));
        // RG-APE-2 : la liste des non-contributeurs appartient au seul bureau de l'APEE.
        self::assertSame(['pub_apee_board'], RoleMatrix::rolesWith('public_schools.apee.non_payers.read'));
        self::assertTrue(RoleMatrix::allows('parent', 'public_schools.apee.read_receipts'));
        self::assertFalse(RoleMatrix::allows('parent', 'public_schools.pupils.read'));
        self::assertFalse(RoleMatrix::allows('inconnu', 'public_schools.pupils.read'));
    }

    public function testLaMatriceEstIdentiqueALaMigrationDesRoles(): void
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 3) . '/database/migrations/202610152351_roles_public_schools.sql');
        preg_match_all("/INSERT INTO roles \(code, level, is_sensitive, label_fr, label_en\) VALUES \('(\w+)', '(\w+)', (\d),/", $sql, $roles, PREG_SET_ORDER);
        preg_match_all("/INSERT INTO role_permissions \(role, permission\) VALUES \('(\w+)', '([\w.]+)'\);/", $sql, $grants, PREG_SET_ORDER);
        $fromMigration = [];
        foreach ($grants as [, $role, $permission]) {
            $fromMigration[$role][] = $permission;
        }
        // Permissions ajoutées par des migrations ultérieures du socle (agrégats territoriaux, journal nominatif).
        $later = ['reporting.territory_aggregates.read', 'identity.named_access.read'];

        self::assertCount(count(RoleMatrix::ROLES), $roles);
        foreach ($roles as [, $code, $level, $sensitive]) {
            self::assertArrayHasKey($code, RoleMatrix::ROLES, $code);
            self::assertSame($level, RoleMatrix::ROLES[$code]['level'], $code);
            self::assertSame($sensitive === '1', RoleMatrix::ROLES[$code]['sensitive'], $code);
            $expected = $fromMigration[$code] ?? [];
            $actual = array_values(array_diff(RoleMatrix::ROLES[$code]['permissions'], $later));
            sort($expected);
            sort($actual);
            self::assertSame($expected, $actual, $code);
        }
        self::assertSame(['public_schools.apee.read_receipts'], $fromMigration['parent']);
        self::assertSame(RoleMatrix::PARENT_PERMISSIONS, $fromMigration['parent']);
    }
}
