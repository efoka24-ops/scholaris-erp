<?php

declare(strict_types=1);

namespace Tests\Feature\Organisation;

use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentDirectory;
use Tests\Support\Organisation\OrganisationTestCase;

/** Un établissement a un seul type, fixé à la création ; hiérarchie client > établissement. */
final class EstablishmentTest extends OrganisationTestCase
{
    protected function setUp(): void
    {
        $this->bootOrganisation();
        $this->grantAll('alice');
        $this->grantAll('bob');
    }

    public function testCreationDUnEtablissementTypeAvecValeursParDefaut(): void
    {
        [$response, $body] = $this->api('POST', '/establishments', 'alice', 't1', null, ['name' => 'Institut Polytechnique', 'type' => 'higher']);

        self::assertSame(201, $response->getStatusCode());
        $data = $body['data'];
        self::assertSame(26, strlen($data['id']), 'Identifiant ULID.');
        self::assertSame('higher', $data['type']);
        self::assertSame('#F26B1D', $data['branding']['accent']);
        self::assertSame('I', $data['branding']['initial']);
        self::assertContains('billing', $data['modules_enabled']);
        self::assertArrayNotHasKey('tenant_id', $data, 'Liste blanche de champs.');
        self::assertMatchesRegularExpression('/^2026-10-08T09:00:00\+01:00$/', $data['created_at']);
        self::assertTrue($this->kernel->container()->get(AuditLog::class)->verify('t1')->intact);
        self::assertSame('establishment.created', $this->connections()->pdo()->query("SELECT action FROM audit_log WHERE tenant_id = 't1'")->fetchColumn());
    }

    public function testLeTypeEstObligatoireEtLimite(): void
    {
        self::assertSame(422, $this->code('POST', '/establishments', 'alice', 't1', null, ['name' => 'X']));
        self::assertSame(422, $this->code('POST', '/establishments', 'alice', 't1', null, ['name' => 'X', 'type' => 'universite']));
        self::assertSame(422, $this->code('POST', '/establishments', 'alice', 't1', null, ['name' => 'X', 'type' => 'higher', 'tenant_id' => 't2']));
        self::assertSame(400, $this->code('POST', '/establishments', 'alice', 't1'), 'Corps absent.');
    }

    public function testLeTypeNeChangeJamaisApresLaCreation(): void
    {
        $id = $this->api('POST', '/establishments', 'alice', 't1', null, ['name' => 'École Les Cèdres', 'type' => 'primary'])[1]['data']['id'];

        [$response, $body] = $this->api('PATCH', '/establishments/' . $id, 'alice', 't1', null, ['name' => 'Cèdres', 'type' => 'secondary']);
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('définitif', $body['errors']['type'][0]);

        [$response, $body] = $this->api('PATCH', '/establishments/' . $id, 'alice', 't1', null, ['name' => 'Les Cèdres']);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('primary', $body['data']['type']);
        self::assertSame('Les Cèdres', $body['data']['name']);
        self::assertSame('primary', $this->api('GET', '/establishments/' . $id, 'alice', 't1')[1]['data']['type']);
    }

    public function testDeuxEtablissementsDuMemeNomRefuses(): void
    {
        $this->api('POST', '/establishments', 'alice', 't1', null, ['name' => 'Lycée', 'type' => 'secondary']);

        self::assertSame(409, $this->code('POST', '/establishments', 'alice', 't1', null, ['name' => 'Lycée', 'type' => 'primary']));
        // Un autre client peut employer le même nom.
        self::assertSame(201, $this->code('POST', '/establishments', 'bob', 't2', null, ['name' => 'Lycée', 'type' => 'primary']));
    }

    public function testListeFiltresEtPagination(): void
    {
        foreach (['Alpha' => 'nursery', 'Bravo' => 'primary', 'Charlie' => 'primary'] as $name => $type) {
            $this->api('POST', '/establishments', 'alice', 't1', null, ['name' => $name, 'type' => $type]);
        }
        $this->api('POST', '/establishments', 'bob', 't2', null, ['name' => 'Delta', 'type' => 'higher']);

        [, $all] = $this->api('GET', '/establishments?sort=-name', 'alice', 't1');
        self::assertSame(['Charlie', 'Bravo', 'Alpha'], array_column($all['data'], 'name'));

        [, $primary] = $this->api('GET', '/establishments?filter[type]=primary&sort=name', 'alice', 't1');
        self::assertSame(['Bravo', 'Charlie'], array_column($primary['data'], 'name'));

        [, $page1] = $this->api('GET', '/establishments?sort=name&per_page=2', 'alice', 't1');
        self::assertSame(['Alpha', 'Bravo'], array_column($page1['data'], 'name'));
        [, $page2] = $this->api('GET', '/establishments?sort=name&per_page=2&cursor=' . $page1['meta']['next_cursor'], 'alice', 't1');
        self::assertSame(['Charlie'], array_column($page2['data'], 'name'));
        self::assertNull($page2['meta']['next_cursor']);
        self::assertNotNull($page1['links']['next']);

        self::assertSame(422, $this->code('GET', '/establishments?filter[tenant_id]=t2', 'alice', 't1'), 'Filtre hors liste blanche.');
        self::assertSame(422, $this->code('GET', '/establishments?sort=branding', 'alice', 't1'), 'Tri hors liste blanche.');
        self::assertSame(422, $this->code('GET', '/establishments?per_page=201', 'alice', 't1'));
        self::assertSame(400, $this->code('GET', '/establishments?cursor=ZZZZ', 'alice', 't1'));
    }

    public function testLaListeSeLimiteAuxEtablissementsAutorises(): void
    {
        $a = $this->api('POST', '/establishments', 'alice', 't1', null, ['name' => 'A', 'type' => 'primary'])[1]['data']['id'];
        $this->api('POST', '/establishments', 'alice', 't1', null, ['name' => 'B', 'type' => 'primary']);
        $this->permissions->grant('carol', 'organisation.read', $a);

        [, $body] = $this->api('GET', '/establishments', 'carol', 't1');
        self::assertSame(['A'], array_column($body['data'], 'name'));
        self::assertSame(403, $this->code('GET', '/establishments', 'dave', 't1'), 'Aucun droit : refus par défaut.');
    }

    public function testAnnuaireDesEtablissementsPourLesAutresModules(): void
    {
        $this->seedEstablishment('t1', 'e1', 'Zénith', 'secondary');
        $this->seedEstablishment('t1', 'e2', 'Aurore', 'nursery');
        $this->seedEstablishment('t2', 'e3', 'Étranger', 'higher');
        $directory = $this->kernel->container()->get(EstablishmentDirectory::class);

        self::assertSame(['Aurore', 'Zénith'], array_map(static fn ($e): string => $e->name, $directory->forTenant('t1')));
        self::assertSame('nursery', $directory->find('t1', 'e2')?->type);
        self::assertSame('#F26B1D', $directory->find('t1', 'e2')?->accent);
        self::assertNull($directory->find('t1', 'e2')?->logoPath);
        self::assertNull($directory->find('t1', 'e3'), 'Un établissement d\'un autre client reste invisible.');
        self::assertSame(['e1'], array_map(static fn ($e): string => $e->id, $directory->findMany('t1', ['e1', 'e3', 'e1', 'inconnu'])));
        self::assertSame([], $directory->forTenant('t3'));
    }
}
