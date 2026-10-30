<?php

declare(strict_types=1);

namespace Tests\Feature\PublicSchools\Territory;

use Tests\Feature\PublicSchools\Pupils\PublicSchoolsTestCase;

/** Écoles d'accueil possibles pour un transfert : territoire de l'utilisateur, écoles ouvertes, aucune donnée sensible. Données SYN-*. */
final class ReceivingSchoolsTest extends PublicSchoolsTestCase
{
    protected function setUp(): void
    {
        $this->bootPublic();
        $this->seedEstablishment('t1', 'e4');
        $this->territories->attach('t1', 'e4', 'A1');
        foreach ([['t1', 'e1', 'SYN-E1', 'open'], ['t1', 'e2', 'SYN-E2', 'open'], ['t1', 'e3', 'SYN-E3', 'open'], ['t1', 'e4', 'SYN-E4', 'closed'], ['t2', 'e9', 'SYN-E9', 'open']] as [$tenant, $establishment, $code, $status]) {
            $this->seedSchool($tenant, $establishment, $code, $status);
        }
        $this->director('dir1', 't1', 'e1', 'public_schools.pupils.read', 'public_schools.pupils.write');
        $this->director('dir9', 't2', 'e9', 'public_schools.pupils.read', 'public_schools.pupils.write');
    }

    /** @return list<string> */
    private function ids(string $query, string $user = 'dir1', string $tenant = 't1', string $establishment = 'e1'): array
    {
        [$response, $json] = $this->api('GET', '/public/schools/receiving' . $query, $user, $tenant, $establishment);
        self::assertSame(200, $response->getStatusCode(), json_encode($json, JSON_THROW_ON_ERROR));

        return array_column($json['data'], 'establishment_id');
    }

    public function testEcolesOuvertesDeLArrondissementSansSoiMemeNiEcoleFermee(): void
    {
        [, $json] = $this->api('GET', '/public/schools/receiving', 'dir1', 't1', 'e1');

        self::assertSame(['e2'], array_column($json['data'], 'establishment_id'));
        self::assertSame(['establishment_id' => 'e2', 'name' => 'École SYN e2', 'official_code' => 'SYN-E2', 'district_id' => 'A1', 'district' => 'SYN A1'], $json['data'][0]);
        self::assertNull($json['meta']['next_cursor']);
    }

    public function testRechercheEtTerritoireDuDepartement(): void
    {
        self::assertSame(['e2', 'e3'], $this->ids('?territory_id=D1'));
        self::assertSame(['e3'], $this->ids('?territory_id=D1&search=' . rawurlencode('syn-e3')));
        self::assertSame(['e3'], $this->ids('?territory_id=D1&search=' . rawurlencode('ECOLE syn e3')), 'Casse et accents ignorés.');
        self::assertSame([], $this->ids('?search=zzz'));
        self::assertSame(['e2'], $this->ids('?territory_id=D1&per_page=1'));
        [, $first] = $this->api('GET', '/public/schools/receiving?territory_id=D1&per_page=1', 'dir1', 't1', 'e1');
        self::assertSame(['e3'], $this->ids('?territory_id=D1&per_page=1&cursor=' . $first['meta']['next_cursor']));
    }

    public function testPerimetreEtDroits(): void
    {
        $this->teacher('maitre', 't1', 'e1', 'classe-x', 'public_schools.pupils.read', 'public_schools.pupils.write');
        $this->supervisor('insp', 't1', 'e1', 'A1', 'pub_district_inspector', 'public_schools.pupils.read', 'public_schools.pupils.write');
        $this->supervisor('insp2', 't1', 'e1', 'A2', 'pub_district_inspector', 'public_schools.pupils.read', 'public_schools.pupils.write');
        $this->supervisor('cabinet', 't1', 'e1', 'R1', 'pub_cabinet', 'public_schools.school.read');
        $this->director('lecteur', 't1', 'e1', 'public_schools.pupils.read');

        self::assertSame(403, $this->code('GET', '/public/schools/receiving', 'maitre', 't1', 'e1'), 'Un enseignant ne demande pas de transfert.');
        self::assertSame(404, $this->code('GET', '/public/schools/receiving', 'cabinet', 't1', 'e1'), 'Agrégats seuls.');
        self::assertSame(404, $this->code('GET', '/public/schools/receiving', 'lecteur', 't1', 'e1'), 'Lecture seule.');
        self::assertSame(['e2'], $this->ids(''), 'Direction.');
        self::assertSame(['e2'], $this->ids('', 'insp'), 'Agent de saisie de l\'inspection.');
        self::assertSame(404, $this->code('GET', '/public/schools/receiving', 'insp2', 't1', 'e1'), 'Autre arrondissement.');
        self::assertSame(404, $this->code('GET', '/public/schools/receiving?territory_id=A2', 'dir1', 't1', 'e1'), 'Territoire hors lignée de l\'école.');
        self::assertSame(404, $this->code('GET', '/public/schools/receiving?territory_id=inconnu', 'dir1', 't1', 'e1'));
    }

    public function testIsolationEntreClients(): void
    {
        self::assertSame([], $this->ids('', 'dir9', 't2', 'e9'), 'e9 est seule dans son arrondissement.');
        self::assertSame(404, $this->code('GET', '/public/schools/receiving?territory_id=A1', 'dir9', 't2', 'e9'), 'Territoire d\'un autre client.');
        self::assertSame(404, $this->code('GET', '/public/schools/receiving?territory_id=AB', 'dir1', 't1', 'e1'));
    }
}
