<?php

declare(strict_types=1);

namespace Tests\Feature\PublicSchools\Pupils;

/** Liste nominative d'une classe (appel) et recherche d'élève : périmètre, finalité, isolation, pagination. Données SYN-*. */
final class PupilListingTest extends PublicSchoolsTestCase
{
    private string $classA = '';
    private string $classB = '';

    protected function setUp(): void
    {
        $this->bootPublic();
        foreach ([['t1', 'e1', 'SYN-E1'], ['t1', 'e3', 'SYN-E3'], ['t2', 'e9', 'SYN-E9']] as [$tenant, $establishment, $code]) {
            $this->seedSchool($tenant, $establishment, $code);
        }
        $this->director('dir1', 't1', 'e1', 'public_schools.pupils.read', 'public_schools.pupils.write');
        $this->director('dir3', 't1', 'e3', 'public_schools.pupils.read', 'public_schools.pupils.write');
        $this->director('dir9', 't2', 'e9', 'public_schools.pupils.read', 'public_schools.pupils.write');
        $this->classA = $this->createClass('t1', 'e1', 'SIL A SYN');
        $this->classB = $this->createClass('t1', 'e1', 'SIL B SYN');
    }

    private function pupil(string $class, string $last, string $first = 'Awa', string $sex = 'F'): string
    {
        [$response, $json] = $this->api('POST', '/public/pupils', 'dir1', 't1', 'e1', [
            'last_name' => $last, 'first_name' => $first, 'birth_date' => '2020-03-14', 'sex' => $sex, 'birth_act_ref' => 'SYN-' . $last, 'class_id' => $class,
            'guardians' => [['kind' => 'parent', 'identity' => ['first_name' => 'Jean', 'last_name' => $last, 'phone' => '677000111']]],
        ]);
        self::assertSame(201, $response->getStatusCode(), json_encode($json, JSON_THROW_ON_ERROR));

        return $json['data']['person_id'];
    }

    public function testListeDeLaClasseTrieeParNom(): void
    {
        $zoe = $this->pupil($this->classA, 'SYN-Zoa', 'Zoe', 'F');
        $abe = $this->pupil($this->classA, 'SYN-Abe', 'Paul', 'M');
        $this->pupil($this->classB, 'SYN-Autre');

        [$response, $json] = $this->api('GET', '/public/classes/' . $this->classA . '/pupils', 'dir1', 't1', 'e1');

        self::assertSame(200, $response->getStatusCode(), json_encode($json, JSON_THROW_ON_ERROR));
        self::assertSame([$abe, $zoe], array_column($json['data'], 'person_id'));
        self::assertSame(['SYN-Abe', 'Paul'], [$json['data'][0]['last_name'], $json['data'][0]['first_name']]);
        self::assertCount(2, array_filter(array_column($json['data'], 'sex')));
        self::assertNotNull($json['data'][0]['learner_number']);
        self::assertNull($json['meta']['next_cursor']);
        self::assertSame(200, $this->code('GET', '/public/classes/' . $this->classA . '/pupils?date=2026-10-21', 'dir1', 't1', 'e1'));
        self::assertSame(422, $this->code('GET', '/public/classes/' . $this->classA . '/pupils?date=hier', 'dir1', 't1', 'e1'));
    }

    public function testPaginationParCurseur(): void
    {
        foreach (['SYN-A', 'SYN-B', 'SYN-C'] as $name) {
            $this->pupil($this->classA, $name);
        }
        $url = '/public/classes/' . $this->classA . '/pupils?per_page=2';
        [, $first] = $this->api('GET', $url, 'dir1', 't1', 'e1');
        self::assertSame(['SYN-A', 'SYN-B'], array_column($first['data'], 'last_name'));
        self::assertNotNull($first['meta']['next_cursor']);
        [, $second] = $this->api('GET', $url . '&cursor=' . $first['meta']['next_cursor'], 'dir1', 't1', 'e1');
        self::assertSame(['SYN-C'], array_column($second['data'], 'last_name'));
        self::assertNull($second['meta']['next_cursor']);
    }

    public function testEnseignantDeLaClasseSeulement(): void
    {
        $mine = $this->pupil($this->classA, 'SYN-Mine');
        $this->pupil($this->classB, 'SYN-Autre');
        $this->teacher('maitre', 't1', 'e1', $this->classA, 'public_schools.pupils.read');

        [, $json] = $this->api('GET', '/public/classes/' . $this->classA . '/pupils', 'maitre', 't1', 'e1');
        self::assertSame([$mine], array_column($json['data'], 'person_id'));
        self::assertSame(404, $this->code('GET', '/public/classes/' . $this->classB . '/pupils', 'maitre', 't1', 'e1'), 'Autre classe.');
    }

    public function testSupervisionTerritorialeFinaliteJournaliseeEtAgregatsSeuls(): void
    {
        $this->pupil($this->classA, 'SYN-Mbarga');
        $this->supervisor('insp', 't1', 'e1', 'A1', 'pub_district_inspector', 'public_schools.pupils.read', 'public_schools.pupils.named.read');
        $this->supervisor('delegue', 't1', 'e1', 'R1', 'pub_regional_delegate', 'public_schools.pupils.read', 'public_schools.pupils.named.read');
        $this->supervisor('insp2', 't1', 'e1', 'A2', 'pub_district_inspector', 'public_schools.pupils.read', 'public_schools.pupils.named.read');
        $this->supervisor('cabinet', 't1', 'e1', 'R1', 'pub_cabinet', 'public_schools.school.read');
        $url = '/public/classes/' . $this->classA . '/pupils';

        self::assertSame(422, $this->code('GET', $url, 'insp', 't1', 'e1'), 'Finalité obligatoire.');
        self::assertSame([], $this->journal->entries);
        [$ok, $json] = $this->api('GET', $url . '?purpose=control', 'insp', 't1', 'e1');
        self::assertSame(200, $ok->getStatusCode());
        self::assertSame(['SYN-Mbarga'], array_column($json['data'], 'last_name'));
        self::assertSame([['tenant' => 't1', 'establishment' => 'e1', 'actor' => 'insp', 'type' => 'class_roster', 'id' => $this->classA, 'purpose' => 'control']], $this->journal->entries);

        self::assertSame(403, $this->code('GET', $url . '?purpose=control', 'delegue', 't1', 'e1'), 'Région : agrégats seuls.');
        self::assertSame(404, $this->code('GET', $url . '?purpose=control', 'cabinet', 't1', 'e1'), 'Rôle à agrégats seuls.');
        self::assertSame(404, $this->code('GET', $url . '?purpose=control', 'insp2', 't1', 'e1'), 'Autre arrondissement.');
        self::assertCount(1, $this->journal->entries);
    }

    public function testIsolationEntreClientsEtEntreEcoles(): void
    {
        $this->pupil($this->classA, 'SYN-Mbarga');
        $url = '/public/classes/' . $this->classA . '/pupils';

        self::assertSame(404, $this->code('GET', $url, 'dir9', 't2', 'e9'), 'Autre client.');
        self::assertSame(404, $this->code('GET', $url, 'dir3', 't1', 'e3'), 'Autre école du même client.');
        self::assertSame(404, $this->code('GET', '/public/classes/inconnue/pupils', 'dir1', 't1', 'e1'));
    }

    public function testRechercheParNomPrenomOuNumero(): void
    {
        $ebo = $this->pupil($this->classA, 'SYN-Étoundi', 'Marie');
        $this->pupil($this->classB, 'SYN-Mbarga', 'Awa');
        [, $listed] = $this->api('GET', '/public/classes/' . $this->classA . '/pupils', 'dir1', 't1', 'e1');
        $number = (string) $listed['data'][0]['learner_number'];

        [$response, $byName] = $this->api('GET', '/public/pupils?search=etoundi', 'dir1', 't1', 'e1');
        self::assertSame(200, $response->getStatusCode(), json_encode($byName, JSON_THROW_ON_ERROR));
        self::assertSame([$ebo], array_column($byName['data'], 'person_id'), 'Casse et accents ignorés.');
        self::assertSame([$ebo], array_column($this->api('GET', '/public/pupils?search=' . rawurlencode('marie syn'), 'dir1', 't1', 'e1')[1]['data'], 'person_id'), 'Tous les mots, nom et prénom.');
        self::assertSame([$ebo], array_column($this->api('GET', '/public/pupils?search=' . rawurlencode($number), 'dir1', 't1', 'e1')[1]['data'], 'person_id'), 'Numéro d\'apprenant.');
        self::assertSame([], $this->api('GET', '/public/pupils?search=zzzz', 'dir1', 't1', 'e1')[1]['data']);
        self::assertCount(2, $this->api('GET', '/public/pupils?search=SYN', 'dir1', 't1', 'e1')[1]['data']);
        self::assertCount(1, $this->api('GET', '/public/pupils?search=SYN&per_page=1', 'dir1', 't1', 'e1')[1]['data']);
        self::assertSame(422, $this->code('GET', '/public/pupils', 'dir1', 't1', 'e1'));
        self::assertSame(422, $this->code('GET', '/public/pupils?search=a', 'dir1', 't1', 'e1'), 'Terme trop court.');
        $this->settings->values['public_schools.pupil_search.min_length'] = 4;
        self::assertSame(422, $this->code('GET', '/public/pupils?search=syn', 'dir1', 't1', 'e1'), 'Longueur minimale paramétrée.');
    }

    public function testRecherchePagineeParCurseur(): void
    {
        foreach (['SYN-A', 'SYN-B', 'SYN-C'] as $name) {
            $this->pupil($this->classA, $name);
        }
        [, $first] = $this->api('GET', '/public/pupils?search=SYN&per_page=2', 'dir1', 't1', 'e1');
        [, $second] = $this->api('GET', '/public/pupils?search=SYN&per_page=2&cursor=' . $first['meta']['next_cursor'], 'dir1', 't1', 'e1');

        self::assertSame(['SYN-A', 'SYN-B', 'SYN-C'], array_merge(array_column($first['data'], 'last_name'), array_column($second['data'], 'last_name')));
        self::assertNull($second['meta']['next_cursor']);
    }

    public function testRecherchePerimetreIsolationEtFinalite(): void
    {
        $mine = $this->pupil($this->classA, 'SYN-Mine');
        $this->pupil($this->classB, 'SYN-Autre');
        $this->teacher('maitre', 't1', 'e1', $this->classA, 'public_schools.pupils.read');
        $this->supervisor('insp', 't1', 'e1', 'A1', 'pub_district_inspector', 'public_schools.pupils.read', 'public_schools.pupils.named.read');
        $this->supervisor('delegue', 't1', 'e1', 'R1', 'pub_regional_delegate', 'public_schools.pupils.read', 'public_schools.pupils.named.read');
        $this->supervisor('insp2', 't1', 'e1', 'A2', 'pub_district_inspector', 'public_schools.pupils.read', 'public_schools.pupils.named.read');
        $this->supervisor('cabinet', 't1', 'e1', 'R1', 'pub_cabinet', 'public_schools.school.read');

        self::assertSame([$mine], array_column($this->api('GET', '/public/pupils?search=SYN', 'maitre', 't1', 'e1')[1]['data'], 'person_id'), 'L\'enseignant ne trouve que sa classe.');
        self::assertSame(422, $this->code('GET', '/public/pupils?search=SYN', 'insp', 't1', 'e1'), 'Finalité obligatoire.');
        self::assertCount(2, $this->api('GET', '/public/pupils?search=SYN&purpose=transfer', 'insp', 't1', 'e1')[1]['data']);
        self::assertSame('pupil_search', $this->journal->entries[0]['type']);
        self::assertSame(403, $this->code('GET', '/public/pupils?search=SYN&purpose=transfer', 'delegue', 't1', 'e1'));
        self::assertSame(404, $this->code('GET', '/public/pupils?search=SYN&purpose=transfer', 'cabinet', 't1', 'e1'));
        self::assertSame(404, $this->code('GET', '/public/pupils?search=SYN&purpose=transfer', 'insp2', 't1', 'e1'));
        self::assertSame([], $this->api('GET', '/public/pupils?search=SYN', 'dir3', 't1', 'e3')[1]['data'], 'Autre école : aucun élève.');
        self::assertSame([], $this->api('GET', '/public/pupils?search=SYN', 'dir9', 't2', 'e9')[1]['data'], 'Autre client : aucun élève.');
    }
}
