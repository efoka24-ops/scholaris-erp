<?php

declare(strict_types=1);

namespace Tests\Feature\Organisation;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Tenancy\TenantContext;
use Scholaris\Modules\Socle\Organisation\Application\ModuleGuard;
use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentDirectory;
use Scholaris\Modules\Socle\Organisation\Http\Middleware\EnsureModuleEnabled;
use Tests\Support\Organisation\OrganisationTestCase;

/** Identité visuelle (T152, T056), vocabulaire (T153) et activation des modules (T057). */
final class BrandingAndModulesTest extends OrganisationTestCase
{
    protected function setUp(): void
    {
        $this->bootOrganisation();
        $this->seedEstablishment('t1', 'e1', 'Lycée', 'secondary', 'alice');
        $this->seedEstablishment('t2', 'e3', 'Étranger', 'higher', 'bob');
        $this->grantAll('alice');
        $this->grantAll('bob');
    }

    public function testCouleurAcceptee(): void
    {
        [$response, $body] = $this->api('PUT', '/establishments/e1/branding', 'alice', 't1', null, ['accent' => '#757575']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('#757575', $body['data']['accent']);
        self::assertSame('#757575', $this->kernel->container()->get(EstablishmentDirectory::class)->find('t1', 'e1')?->accent);
        self::assertSame('#757575', $this->api('GET', '/establishments/e1/branding', 'alice', 't1')[1]['data']['accent']);
    }

    public function testCouleurTropSombreRefuseeAvecUneErreurExplicite(): void
    {
        [$response, $body] = $this->api('PUT', '/establishments/e1/branding', 'alice', 't1', null, ['accent' => '#747474']);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('4,49:1', $body['errors']['accent'][0]);
        self::assertStringContainsString('au moins 4,5:1', $body['errors']['accent'][0]);
        self::assertSame('#F26B1D', $this->api('GET', '/establishments/e1/branding', 'alice', 't1')[1]['data']['accent'], 'Rien n\'est enregistré.');

        self::assertSame(422, $this->code('PUT', '/establishments/e1/branding', 'alice', 't1', null, ['accent' => 'rouge']));
        self::assertSame(422, $this->code('PUT', '/establishments/e1/branding', 'alice', 't1', null, ['accent' => '#000']));
    }

    public function testIdentiteComplete(): void
    {
        $body = [
            'accent' => '#ffd400',
            'logo' => ['path' => 'tenants/t1/establishments/e1/logo/logo.png', 'mime' => 'image/png', 'size' => 20480],
            'headers' => ['République du Cameroun', 'Ministère des Enseignements Secondaires'],
            'signatories' => [['name' => 'Mme Ngo', 'title' => 'Proviseure']],
            'authorization_numbers' => ['ARR-2020-114'],
        ];
        [$response, $json] = $this->api('PUT', '/establishments/e1/branding', 'alice', 't1', null, $body);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('#FFD400', $json['data']['accent']);
        self::assertSame('L', $json['data']['initial'], 'Les champs non envoyés sont conservés.');
        self::assertSame('tenants/t1/establishments/e1/logo/logo.png', $this->kernel->container()->get(EstablishmentDirectory::class)->find('t1', 'e1')?->logoPath);

        self::assertSame(422, $this->code('PUT', '/establishments/e1/branding', 'alice', 't1', null, ['logo' => ['path' => 'tenants/t2/establishments/e3/logo/x.png', 'mime' => 'image/png', 'size' => 10]]));
        self::assertSame(200, $this->code('PUT', '/establishments/e1/branding', 'alice', 't1', null, ['logo' => null]));
        self::assertNull($this->kernel->container()->get(EstablishmentDirectory::class)->find('t1', 'e1')?->logoPath);
    }

    public function testIdentiteReserveeAuxGestionnaires(): void
    {
        $this->permissions->grant('carol', 'organisation.read');
        $this->access->grant('carol', 'e1');

        self::assertSame(200, $this->code('GET', '/establishments/e1/branding', 'carol', 't1'));
        self::assertSame(403, $this->code('PUT', '/establishments/e1/branding', 'carol', 't1', null, ['accent' => '#FFFFFF']));
        self::assertSame(403, $this->code('GET', '/establishments/e1/branding', 'dave', 't1'), 'Refus par défaut.');
        // L'établissement d'un autre client n'existe pas pour Alice.
        self::assertSame(404, $this->code('PUT', '/establishments/e3/branding', 'alice', 't1', null, ['accent' => '#FFFFFF']));
        self::assertSame(404, $this->code('GET', '/establishments/e3/modules', 'alice', 't1'));
    }

    public function testVocabulaireParType(): void
    {
        [$response, $body] = $this->api('GET', '/vocabulary?type=higher', 'alice', 't1');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['learner' => 'étudiant', 'grouping' => 'filière', 'period' => 'semestre', 'document' => 'relevé'], $body['data']['terms']);

        self::assertSame('carnet de suivi', $this->api('GET', '/vocabulary?type=nursery', 'alice', 't1')[1]['data']['terms']['document']);
        self::assertSame('bulletin APC', $this->api('GET', '/vocabulary?type=primary', 'alice', 't1')[1]['data']['terms']['document']);
        self::assertSame('trimestre', $this->api('GET', '/vocabulary?type=secondary', 'alice', 't1')[1]['data']['terms']['period']);
        self::assertCount(4, $this->api('GET', '/vocabulary', 'alice', 't1')[1]['data']);
        self::assertSame(422, $this->code('GET', '/vocabulary?type=doctorat', 'alice', 't1'));
        self::assertSame(401, $this->call('GET', '/api/v1/vocabulary')->getStatusCode());
    }

    public function testActivationDesModules(): void
    {
        [, $body] = $this->api('GET', '/establishments/e1/modules', 'alice', 't1');
        self::assertContains('billing', $body['modules_enabled'] ?? $body['data']['modules_enabled']);

        [$response, $body] = $this->api('PUT', '/establishments/e1/modules', 'alice', 't1', null, ['modules' => ['people', 'documents']]);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['people', 'documents'], $body['data']['modules_enabled']);
        self::assertSame(422, $this->code('PUT', '/establishments/e1/modules', 'alice', 't1', null, ['modules' => ['telepathie']]));

        $guard = $this->kernel->container()->get(ModuleGuard::class);
        $guard->ensure('t1', 'e1', 'people');
        try {
            $guard->ensure('t1', 'e1', 'billing');
            self::fail('Le module billing est désactivé.');
        } catch (HttpException $e) {
            self::assertSame(403, $e->status);
            self::assertStringContainsString('billing', $e->getMessage());
        }
    }

    public function testLeMiddlewareBloqueUnModuleDesactiveEtLaisseUnModuleActif(): void
    {
        $this->api('PUT', '/establishments/e1/modules', 'alice', 't1', null, ['modules' => ['people']]);
        $context = $this->kernel->container()->get(TenantContext::class);
        $guard = $this->kernel->container()->get(ModuleGuard::class);
        $handler = new class () implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                return new Response(204);
            }
        };
        $request = new ServerRequest('GET', '/x');

        $context->set('t1', 'e1');
        self::assertSame(204, (new EnsureModuleEnabled($guard, $context, 'people'))->process($request, $handler)->getStatusCode());
        try {
            (new EnsureModuleEnabled($guard, $context, 'billing'))->process($request, $handler);
            self::fail('403 attendu.');
        } catch (HttpException $e) {
            self::assertSame(403, $e->status);
        }
        // Un établissement d'un autre client est traité comme désactivé.
        $context->set('t2', 'e1');
        $this->expectException(HttpException::class);
        (new EnsureModuleEnabled($guard, $context, 'people'))->process($request, $handler);
    }

    public function testSansEtablissementActifLAccesAuModuleEstRefuse(): void
    {
        $context = new TenantContext();
        $context->set('t1');
        $this->expectException(HttpException::class);
        (new EnsureModuleEnabled($this->kernel->container()->get(ModuleGuard::class), $context, 'people'))->process(
            new ServerRequest('GET', '/x'),
            new class () implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
                {
                    return new Response(204);
                }
            },
        );
    }
}
