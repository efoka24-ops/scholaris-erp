<?php

declare(strict_types=1);

namespace Tests\Feature\PublicNursery;

use Scholaris\Modules\Apps\PublicNursery\Development\Application\FrameService;
use Scholaris\Modules\Apps\PublicNursery\Development\Application\LifeBookService;
use Scholaris\Modules\Apps\PublicNursery\Development\Application\NeedReportService;
use Scholaris\Modules\Socle\Identity\Contracts\PermissionScopes;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;
use Tests\Feature\PublicNursery\Support\NurseryApplicationAccess;
use Tests\Feature\PublicNursery\Support\NurseryScopes;
use Tests\Support\Organisation\OrganisationTestCase;

/** Porte du droit d'usage sur chaque route, pagination par curseur des listes, périmètre de section et validation du corps. */
final class RoutesGateTest extends OrganisationTestCase
{
    private NurseryApplicationAccess $application;

    protected function setUp(): void
    {
        $_ENV['APP_KEY'] = $_SERVER['APP_KEY'] = 'pn-routes-gate-secret';
        $this->application = new NurseryApplicationAccess();
        $this->bootOrganisation([
            ApplicationAccess::class => $this->application,
            PermissionScopes::class => NurseryScopes::binding(),
        ]);
        $this->seedEstablishment('tenant-dev', 'est-dev', 'Maternelle publique pilote', 'nursery', 'admin', 'teacher', 'other');
        $this->seedEstablishment('tenant-dev', 'est-sec', 'Lycée', 'secondary', 'admin');
        $this->grantAll('admin', 'settings.manage');
        $this->grantAll('teacher', 'public_nursery.lifebook.write', 'public_nursery.lifebook.view', 'public_nursery.dev.observe', 'public_nursery.need.report');
    }

    public function testUneApplicationSuspendueRefuseToutesLesEcrituresEn423EtLaisseLaLecture(): void
    {
        $this->application->become(ApplicationAccess::SUSPENDED);

        $writes = 0;
        foreach ($this->routes() as [$method, $path]) {
            [$response, $json] = $this->api($method, $path, 'admin', 'tenant-dev', 'est-dev', $method === 'GET' ? null : []);
            if ($method === 'GET') {
                self::assertNotSame(423, $response->getStatusCode(), $method . ' ' . $path);
                self::assertStringNotContainsString('pas ouverte', (string) ($json['detail'] ?? ''), $method . ' ' . $path . ' : la lecture reste permise');
                continue;
            }
            ++$writes;
            self::assertSame(423, $response->getStatusCode(), $method . ' ' . $path);
            self::assertSame('https://api.scholaris.cm/errors/application-suspended', $json['type'] ?? null, $method . ' ' . $path);
        }

        self::assertGreaterThan(10, $writes);
    }

    public function testUneApplicationFermeeRefuseLesEcrituresEn403EtLaLectureEn404(): void
    {
        $this->application->become(ApplicationAccess::CLOSED);

        foreach ($this->routes() as [$method, $path]) {
            [$response, $json] = $this->api($method, $path, 'admin', 'tenant-dev', 'est-dev', $method === 'GET' ? null : []);
            if ($method === 'GET') {
                self::assertSame(404, $response->getStatusCode(), $method . ' ' . $path);
                continue;
            }
            self::assertSame(403, $response->getStatusCode(), $method . ' ' . $path);
            self::assertSame('https://api.scholaris.cm/errors/application-closed', $json['type'] ?? null, $method . ' ' . $path);
        }
    }

    public function testLesRoutesDeveloppementRefusentUnEtablissementQuiNEstPasUneMaternelle(): void
    {
        foreach (['/public-nursery/development/frames', '/public-nursery/development/needs', '/public-nursery/development/lifebook'] as $path) {
            self::assertSame(404, $this->api('GET', $path, 'admin', 'tenant-dev', 'est-sec')[0]->getStatusCode(), $path);
        }
        self::assertSame(404, $this->api('POST', '/public-nursery/sync/observations', 'admin', 'tenant-dev', 'est-sec', [])[0]->getStatusCode());
    }

    public function testLesListesSontPagineesParCurseur(): void
    {
        $container = $this->kernel->container();
        $needs = $container->get(NeedReportService::class);
        $frames = $container->get(FrameService::class);
        $lifeBooks = $container->get(LifeBookService::class);
        for ($i = 1; $i <= 3; ++$i) {
            $needs->create('tenant-dev', 'est-dev', 'teacher', ['learner_id' => 'learner-' . $i, 'need_area' => 'language', 'summary' => 'Besoin ' . $i]);
            $frames->save('tenant-dev', ['frame_code' => 'FRAME-' . $i, 'version' => 1, 'subsystem' => 'fr', 'label_fr' => 'Cadre ' . $i, 'label_en' => 'Frame ' . $i, 'status' => 'draft']);
            $entry = $lifeBooks->saveDraft('tenant-dev', 'est-dev', 'section-a', 'teacher', ['title' => 'Atelier ' . $i, 'activity_on' => '2026-10-0' . $i]);
            $lifeBooks->publish('tenant-dev', 'est-dev', (string) $entry['id']);
        }

        foreach ([
            ['/public-nursery/development/needs', 'teacher'],
            ['/public-nursery/development/frames', 'admin'],
            ['/public-nursery/development/lifebook', 'teacher'],
        ] as [$path, $user]) {
            [$first, $page1] = $this->api('GET', $path . '?per_page=2', $user, 'tenant-dev', 'est-dev');
            self::assertSame(200, $first->getStatusCode(), $path);
            self::assertCount(2, $page1['data'], $path);
            self::assertSame(2, $page1['meta']['per_page'], $path);
            self::assertIsString($page1['meta']['next_cursor'], $path);

            [$second, $page2] = $this->api('GET', $path . '?per_page=2&cursor=' . rawurlencode($page1['meta']['next_cursor']), $user, 'tenant-dev', 'est-dev');
            self::assertSame(200, $second->getStatusCode(), $path);
            self::assertCount(1, $page2['data'], $path);
            self::assertNull($page2['meta']['next_cursor'], $path);
            self::assertNotSame($page1['data'][0]['id'], $page2['data'][0]['id'], $path);
            self::assertNotContains($page2['data'][0]['id'], array_column($page1['data'], 'id'), $path);

            [$all, $whole] = $this->api('GET', $path, $user, 'tenant-dev', 'est-dev');
            self::assertSame(200, $all->getStatusCode(), $path);
            self::assertCount(3, $whole['data'], $path);
            self::assertNull($whole['meta']['next_cursor'], $path);

            self::assertSame(422, $this->api('GET', $path . '?per_page=201', $user, 'tenant-dev', 'est-dev')[0]->getStatusCode(), $path);
            self::assertSame(422, $this->api('GET', $path . '?per_page=0', $user, 'tenant-dev', 'est-dev')[0]->getStatusCode(), $path);
        }
    }

    public function testLeCahierDeVieEtLesObservationsRestentDansLaSectionDuRole(): void
    {
        $this->kernel->container()->get(PermissionScopes::class)->restrictToClasses('teacher', 'section-a');
        $lifeBooks = $this->kernel->container()->get(LifeBookService::class);
        $other = $lifeBooks->saveDraft('tenant-dev', 'est-dev', 'section-b', 'admin', ['title' => 'Autre section', 'activity_on' => '2026-10-02']);
        $lifeBooks->publish('tenant-dev', 'est-dev', (string) $other['id']);

        self::assertSame(404, $this->api('POST', '/public-nursery/development/lifebook', 'teacher', 'tenant-dev', 'est-dev', ['structure_id' => 'section-b', 'title' => 'Hors périmètre', 'activity_on' => '2026-10-03'])[0]->getStatusCode());
        self::assertSame(0, (int) $this->connections()->pdo()->query("SELECT COUNT(*) FROM pn_lifebook_entries WHERE title = 'Hors périmètre'")->fetchColumn());
        [$created, $mine] = $this->api('POST', '/public-nursery/development/lifebook', 'teacher', 'tenant-dev', 'est-dev', ['structure_id' => 'section-a', 'title' => 'Ma section', 'activity_on' => '2026-10-03']);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());

        self::assertSame(404, $this->api('GET', '/public-nursery/development/lifebook?structure_id=section-b', 'teacher', 'tenant-dev', 'est-dev')[0]->getStatusCode());
        self::assertSame(404, $this->api('GET', '/public-nursery/development/lifebook/' . $other['id'], 'teacher', 'tenant-dev', 'est-dev')[0]->getStatusCode());
        self::assertSame(404, $this->api('POST', '/public-nursery/development/lifebook/' . $other['id'] . '/publish', 'teacher', 'tenant-dev', 'est-dev', [])[0]->getStatusCode());
        self::assertSame(404, $this->api('POST', '/public-nursery/development/lifebook/' . $other['id'] . '/media', 'teacher', 'tenant-dev', 'est-dev', [])[0]->getStatusCode());
        self::assertSame(200, $this->api('POST', '/public-nursery/development/lifebook/' . $mine['data']['id'] . '/publish', 'teacher', 'tenant-dev', 'est-dev', [])[0]->getStatusCode());

        [, $list] = $this->api('GET', '/public-nursery/development/lifebook', 'teacher', 'tenant-dev', 'est-dev');
        self::assertSame([$mine['data']['id']], array_column($list['data'], 'id'));

        [, $everything] = $this->api('GET', '/public-nursery/development/lifebook', 'admin', 'tenant-dev', 'est-dev');
        self::assertCount(2, $everything['data']);

        $observation = [
            'learner_id' => 'learner-1', 'frame_id' => 'frame-1', 'domain_id' => 'domain-1', 'item_id' => 'item-1',
            'level_code' => 'acquired', 'observed_on' => '2026-10-16', 'observer_id' => 'teacher',
        ];
        self::assertSame(404, $this->api('POST', '/public-nursery/sync/observations', 'teacher', 'tenant-dev', 'est-dev', $observation + ['structure_id' => 'section-b'])[0]->getStatusCode());
        self::assertSame(201, $this->api('POST', '/public-nursery/sync/observations', 'teacher', 'tenant-dev', 'est-dev', $observation + ['structure_id' => 'section-a'])[0]->getStatusCode());
    }

    public function testLeReferentielRefuseUnCorpsVideOuMalTypeEn422SansErreurServeur(): void
    {
        foreach ([[], ['frame_code' => ['FRAME-PS'], 'version' => 'abc', 'subsystem' => 7, 'label_fr' => null, 'label_en' => [], 'status' => true]] as $body) {
            [$response, $json] = $this->api('POST', '/public-nursery/development/frames', 'admin', 'tenant-dev', 'est-dev', $body);
            self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
            self::assertArrayHasKey('frame_code', $json['errors']);
            self::assertArrayHasKey('version', $json['errors']);
        }

        [$created, $json] = $this->api('POST', '/public-nursery/development/frames', 'admin', 'tenant-dev', 'est-dev', [
            'frame_code' => 'FRAME-PS', 'version' => 1, 'subsystem' => 'fr', 'label_fr' => 'Cadre PS', 'label_en' => 'PS frame', 'status' => 'draft',
        ]);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        self::assertSame('FRAME-PS', $json['data']['frame_code']);
        self::assertSame(1, $json['data']['version']);
    }

    /** @return list<array{0: string, 1: string}> méthode et chemin (sans /api/v1) de chaque route de la Maternelle publique */
    private function routes(): array
    {
        $routes = [];
        foreach ($this->kernel->routes()->all() as $route) {
            if (!str_starts_with($route['path'], '/api/v1/public-nursery/')) {
                continue;
            }
            $routes[] = [$route['method'], str_replace(['{id}', '{mediaId}'], ['x', 'y'], substr($route['path'], strlen('/api/v1')))];
        }

        return $routes;
    }
}
