<?php

declare(strict_types=1);

namespace Tests\Isolation;

use Tests\Support\Organisation\OrganisationTestCase;

/** Aucune fuite entre clients ni entre établissements, quelle que soit la ressource du module Organisation. */
final class OrganisationIsolationTest extends OrganisationTestCase
{
    /** @var array<string, string> chemin de ressource => identifiant créé chez le client t1, établissement e1 */
    private array $ids = [];

    protected function setUp(): void
    {
        $this->bootOrganisation();
        $this->seedEstablishment('t1', 'e1', 'Lycée', 'higher', 'alice');
        $this->seedEstablishment('t1', 'e2', 'Collège', 'higher', 'alice');
        $this->seedEstablishment('t2', 'e3', 'Étranger', 'higher', 'bob');
        $this->grantAll('alice');
        $this->grantAll('bob');

        $year = $this->api('POST', '/academic-years', 'alice', 't1', 'e1', ['label' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30'])[1]['data']['id'];
        $structure = $this->api('POST', '/structures', 'alice', 't1', 'e1', ['kind' => 'track', 'name' => 'Filière'])[1]['data']['id'];
        $this->ids = [
            '/academic-years' => $year,
            '/structures' => $structure,
            '/org-units' => $this->api('POST', '/org-units', 'alice', 't1', 'e1', ['type' => 'department', 'name' => 'U'])[1]['data']['id'],
            '/rooms' => $this->api('POST', '/rooms', 'alice', 't1', 'e1', ['name' => 'R'])[1]['data']['id'],
            '/periods' => $this->api('POST', '/periods', 'alice', 't1', 'e1', ['academic_year_id' => $year, 'kind' => 'semester', 'ordinal' => 1, 'starts_on' => '2026-09-01', 'ends_on' => '2027-01-31'])[1]['data']['id'],
            '/authorizations' => $this->api('POST', '/authorizations', 'alice', 't1', 'e1', ['structure_id' => $structure, 'reference' => 'A1', 'valid_from' => '2026-01-01', 'valid_to' => '2026-12-31'])[1]['data']['id'],
        ];
        $this->api('PUT', '/settings', 'alice', 't1', 'e1', ['scope' => 'establishment', 'key' => 'a.b', 'value' => 1]);
    }

    public function testLIdentifiantDUnAutreClientDonne404(): void
    {
        // Bob (client t2, établissement e3) connaît les identifiants du client t1.
        foreach ($this->ids as $path => $id) {
            self::assertSame(404, $this->code('GET', $path . '/' . $id, 'bob', 't2', 'e3'), 'GET ' . $path);
            self::assertSame(404, $this->code('PATCH', $path . '/' . $id, 'bob', 't2', 'e3', ['name' => 'piraté', 'label' => 'p', 'reference' => 'p']), 'PATCH ' . $path);
        }
        self::assertSame(404, $this->code('POST', '/academic-years/' . $this->ids['/academic-years'] . '/close', 'bob', 't2', 'e3'));
        self::assertSame(404, $this->code('GET', '/structures/' . $this->ids['/structures'] . '/enrolment', 'bob', 't2', 'e3'));
        self::assertSame(404, $this->code('GET', '/establishments/e1', 'bob', 't2'));
        self::assertSame(404, $this->code('PATCH', '/establishments/e1', 'bob', 't2', null, ['name' => 'piraté']));
        self::assertSame(404, $this->code('PUT', '/establishments/e1/modules', 'bob', 't2', null, ['modules' => []]));
        self::assertSame('open', $this->api('GET', '/academic-years/' . $this->ids['/academic-years'], 'alice', 't1', 'e1')[1]['data']['status']);
    }

    public function testLesListesNeContiennentQueLesDonneesDuClientEtDeLEtablissement(): void
    {
        foreach (array_keys($this->ids) as $path) {
            self::assertSame([], $this->api('GET', $path, 'bob', 't2', 'e3')[1]['data'], 'Liste ' . $path . ' chez l\'autre client.');
            self::assertSame([], $this->api('GET', $path, 'alice', 't1', 'e2')[1]['data'], 'Liste ' . $path . ' dans l\'autre établissement.');
            self::assertCount(1, $this->api('GET', $path, 'alice', 't1', 'e1')[1]['data'], 'Liste ' . $path . ' chez le propriétaire.');
            self::assertSame(404, $this->code('GET', $path . '/' . $this->ids[$path], 'alice', 't1', 'e2'), 'Détail ' . $path . ' via un autre établissement.');
        }
        self::assertSame([], $this->api('GET', '/establishments?filter[type]=nursery', 'bob', 't2')[1]['data']);
        self::assertSame(['e3'], array_column($this->api('GET', '/establishments', 'bob', 't2')[1]['data'], 'id'));
    }

    public function testEnTeteVersLEtablissementDUnAutreClient(): void
    {
        // Le contrôle d'accès du noyau refuse déjà ; même s'il laissait passer, le module ne servirait rien.
        self::assertSame(403, $this->code('GET', '/rooms', 'bob', 't2', 'e1'));
        $this->access->grant('bob', 'e1');
        foreach (array_keys($this->ids) as $path) {
            $code = $this->code('GET', $path, 'bob', 't2', 'e1');
            self::assertContains($code, [403, 404], 'GET ' . $path);
        }
        self::assertSame(404, $this->code('POST', '/rooms', 'bob', 't2', 'e1', ['name' => 'infiltrée']));
        self::assertSame(0, (int) $this->connections()->pdo()->query("SELECT COUNT(*) FROM rooms WHERE tenant_id = 't2'")->fetchColumn());
    }
}
