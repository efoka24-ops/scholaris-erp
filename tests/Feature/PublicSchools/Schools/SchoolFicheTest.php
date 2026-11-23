<?php

declare(strict_types=1);

namespace Tests\Feature\PublicSchools\Schools;

use Tests\Feature\PublicSchools\Pupils\PublicSchoolsTestCase;

/** EX-ECO-01 et RG-TER-5 : fiche de l'école publique, acte de création, isolation par client et par école. Données SYN-*. */
final class SchoolFicheTest extends PublicSchoolsTestCase
{
    private const FICHE = [
        'official_code' => 'SYN-ECOLE-001',
        'creation_act_ref' => 'SYN-DECRET-2020-14',
        'creation_act_date' => '2020-09-01',
        'subsystem' => 'francophone',
        'cycle' => 'primary',
        'status' => 'open',
        'latitude' => 9.3012,
        'longitude' => 13.3921,
        'phone' => '+237 600 000 001',
        'email' => 'ecole-syn@example.test',
    ];

    protected function setUp(): void
    {
        $this->bootPublic();
        $this->director('dir1', 't1', 'e1', 'public_schools.school.read', 'public_schools.school.write');
        $this->director('dir9', 't2', 'e9', 'public_schools.school.read', 'public_schools.school.write');
    }

    public function testLeDirecteurCreeEtLitLaFicheDeSonEcole(): void
    {
        [$created, $json] = $this->api('PUT', '/public/schools/current', 'dir1', 't1', 'e1', self::FICHE);
        self::assertSame(201, $created->getStatusCode());
        self::assertSame('SYN-ECOLE-001', $json['data']['official_code']);
        self::assertTrue($json['data']['official']);
        self::assertSame('9.3012', $json['data']['latitude']);

        [$read, $body] = $this->api('GET', '/public/schools/current', 'dir1', 't1', 'e1');
        self::assertSame(200, $read->getStatusCode());
        self::assertSame('primary', $body['data']['cycle']);
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM audit_log WHERE tenant_id = ? AND action = ?', ['t1', 'public_schools.school.created']));
    }

    public function testUneEcoleSansActeDeCreationNEstPasOfficielle(): void
    {
        $fiche = array_diff_key(self::FICHE, ['creation_act_ref' => 1, 'creation_act_date' => 1]);
        [$response, $json] = $this->api('PUT', '/public/schools/current', 'dir1', 't1', 'e1', $fiche);

        self::assertSame(201, $response->getStatusCode());
        self::assertFalse($json['data']['official']);
        self::assertNull($json['data']['creation_act_ref']);
    }

    public function testLaMiseAJourGardeLesChampsAbsentsEtRefuseUneDateSansActe(): void
    {
        $this->api('PUT', '/public/schools/current', 'dir1', 't1', 'e1', self::FICHE);

        [$updated, $json] = $this->api('PUT', '/public/schools/current', 'dir1', 't1', 'e1', ['status' => 'suspended']);
        self::assertSame(200, $updated->getStatusCode());
        self::assertSame('suspended', $json['data']['status']);
        self::assertSame('SYN-ECOLE-001', $json['data']['official_code']);

        [$refused] = $this->api('PUT', '/public/schools/current', 'dir1', 't1', 'e1', ['creation_act_ref' => null]);
        self::assertSame(422, $refused->getStatusCode(), 'La date de l\'acte reste sans référence.');
    }

    public function testValidationDesChamps(): void
    {
        foreach ([
            ['subsystem' => 'autre'],
            ['cycle' => 'secondary'],
            ['latitude' => 120.0, 'longitude' => 13.0],
            ['longitude' => null],
            ['email' => 'pas-un-email'],
            ['school_group_ref' => 'xx'],
        ] as $invalid) {
            self::assertSame(422, $this->code('PUT', '/public/schools/current', 'dir1', 't1', 'e1', $invalid + self::FICHE), json_encode($invalid, JSON_THROW_ON_ERROR));
        }
    }

    public function testLeCodeOfficielEstUniqueDansLeClient(): void
    {
        $this->director('dir2', 't1', 'e2', 'public_schools.school.write');
        self::assertSame(201, $this->code('PUT', '/public/schools/current', 'dir1', 't1', 'e1', self::FICHE));

        self::assertSame(409, $this->code('PUT', '/public/schools/current', 'dir2', 't1', 'e2', self::FICHE));
        self::assertSame(201, $this->code('PUT', '/public/schools/current', 'dir9', 't2', 'e9', self::FICHE), 'Un autre client peut réutiliser le code.');
    }

    public function testLeGroupeScolaireRelieLaMaternelleEtLePrimaire(): void
    {
        $this->director('dir2', 't1', 'e2', 'public_schools.school.read', 'public_schools.school.write');
        [, $first] = $this->api('PUT', '/public/schools/current', 'dir1', 't1', 'e1', ['new_group' => true] + self::FICHE);
        $group = (string) $first['data']['school_group_ref'];
        $this->api('PUT', '/public/schools/current', 'dir2', 't1', 'e2', ['school_group_ref' => $group, 'cycle' => 'nursery', 'official_code' => 'SYN-ECOLE-002'] + self::FICHE);

        $registry = $this->kernel->container()->get(\Scholaris\Modules\Apps\PublicSchools\Contracts\SchoolRegistry::class);
        self::assertSame(['SYN-ECOLE-001', 'SYN-ECOLE-002'], array_map(static fn ($school): string => $school->officialCode, $registry->groupOf('t1', 'e1')));
        self::assertSame([], $registry->groupOf('t2', 'e1'), 'Un autre client ne voit pas le groupe.');
    }

    public function testLEcritureEstRefuseeAuSansPermissionEtAuTerritorial(): void
    {
        $this->supervisor('insp', 't1', 'e1', 'A1', 'pub_district_inspector', 'public_schools.school.read', 'public_schools.school.write');
        $this->teacher('maitre', 't1', 'e1', 'c1', 'public_schools.school.read', 'public_schools.school.write');
        $this->access->grant('nobody', 'e1');

        self::assertSame(403, $this->code('PUT', '/public/schools/current', 'insp', 't1', 'e1', self::FICHE));
        self::assertSame(403, $this->code('PUT', '/public/schools/current', 'maitre', 't1', 'e1', self::FICHE));
        self::assertSame(403, $this->code('PUT', '/public/schools/current', 'nobody', 't1', 'e1', self::FICHE));
        self::assertSame(404, $this->code('GET', '/public/schools/current', 'nobody', 't1', 'e1'));
    }

    public function testLEcritureEstBloqueeQuandLApplicationEstSuspendue(): void
    {
        $this->applications->set('t1', 'public_primary', \Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess::SUSPENDED);

        self::assertSame(423, $this->code('PUT', '/public/schools/current', 'dir1', 't1', 'e1', self::FICHE));
        self::assertSame(404, $this->code('GET', '/public/schools/current', 'dir1', 't1', 'e1'), 'Pas de fiche : lecture permise mais fiche absente.');
    }

    public function testIsolationEntreClients(): void
    {
        $this->api('PUT', '/public/schools/current', 'dir1', 't1', 'e1', self::FICHE);

        self::assertSame(404, $this->code('GET', '/public/schools/current', 'dir9', 't2', 'e9'));
        // Un directeur d'un autre client ne peut pas viser l'établissement e1 : l'accès à l'établissement est refusé.
        self::assertContains($this->code('GET', '/public/schools/current', 'dir9', 't2', 'e1'), [403, 404]);
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM pub_schools WHERE tenant_id = ?', ['t2']));
    }
}
