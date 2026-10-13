<?php

declare(strict_types=1);

namespace Tests\Feature\Organisation;

use Tests\Support\Organisation\OrganisationTestCase;

/** Unités internes, structures et salles : hiérarchie, références contrôlées, permissions refusées par défaut. */
final class ResourcesCrudTest extends OrganisationTestCase
{
    protected function setUp(): void
    {
        $this->bootOrganisation();
        $this->seedEstablishment('t1', 'e1', 'Lycée', 'secondary', 'alice', 'reader', 'nobody');
        $this->seedEstablishment('t1', 'e2', 'Collège', 'secondary', 'alice');
        $this->grantAll('alice');
        $this->permissions->grant('reader', 'organisation.read');
    }

    public function testHierarchieDesUnitesInternes(): void
    {
        $root = $this->api('POST', '/org-units', 'alice', 't1', 'e1', ['type' => 'department', 'name' => 'Sciences'])[1]['data'];
        $child = $this->api('POST', '/org-units', 'alice', 't1', 'e1', ['type' => 'section', 'name' => 'Maths', 'parent_id' => $root['id']])[1]['data'];
        self::assertSame($root['id'], $child['parent_id']);
        self::assertSame('e1', $child['establishment_id']);

        self::assertSame(422, $this->code('PATCH', '/org-units/' . $root['id'], 'alice', 't1', 'e1', ['parent_id' => $child['id']]), 'Cycle refusé.');
        self::assertSame(422, $this->code('PATCH', '/org-units/' . $root['id'], 'alice', 't1', 'e1', ['parent_id' => $root['id']]));
        self::assertSame(422, $this->code('POST', '/org-units', 'alice', 't1', 'e2', ['type' => 'section', 'name' => 'X', 'parent_id' => $root['id']]), 'Parent d\'un autre établissement.');

        [, $list] = $this->api('GET', '/org-units?filter[parent_id]=' . $root['id'], 'alice', 't1', 'e1');
        self::assertSame(['Maths'], array_column($list['data'], 'name'));
        self::assertSame(422, $this->code('GET', '/org-units?filter[name]=Maths', 'alice', 't1', 'e1'), 'Filtre hors liste blanche.');
    }

    public function testStructuresPedagogiques(): void
    {
        $unit = $this->api('POST', '/org-units', 'alice', 't1', 'e1', ['type' => 'department', 'name' => 'Sciences'])[1]['data']['id'];

        [$response, $body] = $this->api('POST', '/structures', 'alice', 't1', 'e1', ['kind' => 'class', 'name' => '2nde C', 'capacity' => 55, 'org_unit_id' => $unit]);
        self::assertSame(201, $response->getStatusCode());
        $id = $body['data']['id'];

        self::assertSame(422, $this->code('POST', '/structures', 'alice', 't1', 'e1', ['kind' => 'track', 'name' => 'Filière']), 'Filière réservée au supérieur.');
        self::assertSame(422, $this->code('POST', '/structures', 'alice', 't1', 'e1', ['kind' => 'class', 'name' => 'Z', 'capacity' => 0]));
        self::assertSame(422, $this->code('POST', '/structures', 'alice', 't1', 'e2', ['kind' => 'class', 'name' => 'Z', 'org_unit_id' => $unit]), 'Unité d\'un autre établissement.');
        self::assertSame(200, $this->code('PATCH', '/structures/' . $id, 'alice', 't1', 'e1', ['capacity' => 60]));
        self::assertSame(60, $this->api('GET', '/structures/' . $id, 'alice', 't1', 'e1')[1]['data']['capacity']);

        [, $sorted] = $this->api('GET', '/structures?filter[kind]=class&sort=-name', 'alice', 't1', 'e1');
        self::assertSame(['2nde C'], array_column($sorted['data'], 'name'));
    }

    public function testSallesEtPagination(): void
    {
        foreach (['Salle A', 'Salle B', 'Salle C'] as $i => $name) {
            [$response] = $this->api('POST', '/rooms', 'alice', 't1', 'e1', ['name' => $name, 'capacity' => 30 + $i, 'equipment' => ['projecteur']]);
            self::assertSame(201, $response->getStatusCode());
        }
        self::assertSame(409, $this->code('POST', '/rooms', 'alice', 't1', 'e1', ['name' => 'Salle A']));
        self::assertSame(201, $this->code('POST', '/rooms', 'alice', 't1', 'e2', ['name' => 'Salle A']), 'Même nom dans un autre établissement.');

        [, $page1] = $this->api('GET', '/rooms?per_page=2&sort=name', 'alice', 't1', 'e1');
        self::assertSame(['Salle A', 'Salle B'], array_column($page1['data'], 'name'));
        self::assertSame(['projecteur'], $page1['data'][0]['equipment']);
        [, $page2] = $this->api('GET', '/rooms?per_page=2&sort=name&cursor=' . $page1['meta']['next_cursor'], 'alice', 't1', 'e1');
        self::assertSame(['Salle C'], array_column($page2['data'], 'name'));
        self::assertSame(422, $this->code('GET', '/rooms?sort=tenant_id', 'alice', 't1', 'e1'));
        self::assertSame(422, $this->code('POST', '/rooms', 'alice', 't1', 'e1', ['name' => 'D', 'tenant_id' => 't2']));
        self::assertSame(422, $this->code('POST', '/rooms', 'alice', 't1', 'e1', ['name' => 'D', 'establishment_id' => 'e2']));
    }

    public function testPermissionsRefuseesParDefaut(): void
    {
        $room = $this->api('POST', '/rooms', 'alice', 't1', 'e1', ['name' => 'Salle A'])[1]['data']['id'];

        // Lecture seule : peut lire, ne peut pas écrire.
        self::assertSame(200, $this->code('GET', '/rooms', 'reader', 't1', 'e1'));
        self::assertSame(200, $this->code('GET', '/rooms/' . $room, 'reader', 't1', 'e1'));
        foreach (['/rooms', '/org-units', '/structures', '/academic-years', '/periods', '/authorizations'] as $path) {
            self::assertSame(403, $this->code('POST', $path, 'reader', 't1', 'e1', ['name' => 'x']), 'POST ' . $path);
        }
        self::assertSame(403, $this->code('PATCH', '/rooms/' . $room, 'reader', 't1', 'e1', ['name' => 'B']));

        // Aucun droit : tout est refusé.
        foreach (['/rooms', '/org-units', '/structures', '/academic-years', '/periods', '/authorizations', '/authorizations/expiring', '/settings', '/settings/effective'] as $path) {
            self::assertSame(403, $this->code('GET', $path, 'nobody', 't1', 'e1'), 'GET ' . $path);
        }
        self::assertSame(403, $this->code('POST', '/establishments', 'reader', 't1', null, ['name' => 'N', 'type' => 'primary']), 'Créer un établissement exige organisation.manage sur tout le client.');
        self::assertSame(403, $this->code('POST', '/academic-years/x/close', 'reader', 't1', 'e1'));

        // Un droit accordé sur un seul établissement ne vaut pas pour un autre.
        $this->permissions->grant('scoped', 'organisation.manage', 'e2');
        $this->access->grant('scoped', 'e1');
        $this->access->grant('scoped', 'e2');
        self::assertSame(403, $this->code('POST', '/rooms', 'scoped', 't1', 'e1', ['name' => 'Y']));
        self::assertSame(201, $this->code('POST', '/rooms', 'scoped', 't1', 'e2', ['name' => 'Y']));
    }

    public function testToutesLesEcrituresSontAuditees(): void
    {
        $room = $this->api('POST', '/rooms', 'alice', 't1', 'e1', ['name' => 'Salle A'])[1]['data']['id'];
        $this->api('PATCH', '/rooms/' . $room, 'alice', 't1', 'e1', ['capacity' => 40]);

        $rows = $this->connections()->pdo()->query("SELECT action, object_id, actor_id, before_json, after_json FROM audit_log WHERE tenant_id = 't1' ORDER BY seq")->fetchAll();
        self::assertSame(['room.created', 'room.updated'], array_column($rows, 'action'));
        self::assertSame([$room, $room], array_column($rows, 'object_id'));
        self::assertSame(['alice', 'alice'], array_column($rows, 'actor_id'));
        self::assertSame(40, json_decode((string) $rows[1]['after_json'], true)['capacity']);
        self::assertNull(json_decode((string) $rows[1]['before_json'], true)['capacity']);
    }
}
