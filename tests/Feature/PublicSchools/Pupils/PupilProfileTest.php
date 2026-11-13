<?php

declare(strict_types=1);

namespace Tests\Feature\PublicSchools\Pupils;

/** Fiche élève (EX-ELV-04, RG-ELV-2), liste DOC-07 et lecture nominative par périmètre (RG-TER-2, RG-TER-3). Données SYN-*. */
final class PupilProfileTest extends PublicSchoolsTestCase
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

    private function pupil(string $class, string $last, ?string $act = null): string
    {
        [$response, $json] = $this->api('POST', '/public/pupils', 'dir1', 't1', 'e1', [
            'last_name' => $last, 'first_name' => 'Awa', 'birth_date' => '2020-03-14', 'sex' => 'F', 'birth_act_ref' => $act, 'class_id' => $class,
            'guardians' => [['kind' => 'parent', 'identity' => ['first_name' => 'Jean', 'last_name' => $last, 'phone' => '677000111']]],
        ]);
        self::assertSame(201, $response->getStatusCode(), json_encode($json, JSON_THROW_ON_ERROR));

        return $json['data']['person_id'];
    }

    public function testActeDeNaissanceEtSituationsParticulieres(): void
    {
        $id = $this->pupil($this->classA, 'SYN-Mbarga');

        [$updated, $json] = $this->api('PATCH', '/public/pupils/' . $id, 'dir1', 't1', 'e1', [
            'birth_act_ref' => 'SYN-ACTE-77', 'birth_act_verified' => true, 'home_language' => 'fulfulde', 'is_displaced' => true, 'accommodation_type' => 'large_print',
        ]);

        self::assertSame(200, $updated->getStatusCode(), json_encode($json, JSON_THROW_ON_ERROR));
        self::assertTrue($json['data']['birth_act_verified']);
        self::assertSame('SYN-ACTE-77', $json['data']['birth_act_ref']);
        self::assertTrue($json['data']['situations']['is_displaced']);
        self::assertFalse($json['data']['situations']['is_refugee']);
        self::assertSame('large_print', $json['data']['situations']['accommodation_type']);

        [, $shown] = $this->api('GET', '/public/pupils/' . $id, 'dir1', 't1', 'e1');
        self::assertSame($this->classA, $shown['data']['structure_id']);
        self::assertFalse($shown['data']['read_only']);
    }

    public function testValidationDeLaFiche(): void
    {
        $id = $this->pupil($this->classA, 'SYN-Mbarga');

        foreach ([
            ['birth_act_verified' => true],
            ['apee_status' => 'paid'],
            ['is_orphan' => 'oui'],
            ['accommodation_type' => 'Autisme sévère diagnostiqué'],
            ['home_language' => str_repeat('x', 41)],
        ] as $invalid) {
            self::assertSame(422, $this->code('PATCH', '/public/pupils/' . $id, 'dir1', 't1', 'e1', $invalid), json_encode($invalid, JSON_THROW_ON_ERROR));
        }
    }

    public function testListeDesElevesSansActeEtTransmissionTrimestrielle(): void
    {
        $without = $this->pupil($this->classA, 'SYN-Sans');
        $this->pupil($this->classB, 'SYN-Avec', 'SYN-ACTE-9');

        [, $list] = $this->api('GET', '/public/pupils/without-birth-act', 'dir1', 't1', 'e1');
        self::assertSame([$without], array_column($list['data'], 'person_id'));

        [$transmitted, $record] = $this->api('POST', '/public/pupils/without-birth-act/transmissions', 'dir1', 't1', 'e1', []);
        self::assertSame(201, $transmitted->getStatusCode());
        self::assertSame('2026-T4', $record['data']['quarter']);
        self::assertSame(1, $record['data']['pupil_count']);

        $this->api('PATCH', '/public/pupils/' . $without, 'dir1', 't1', 'e1', ['birth_act_ref' => 'SYN-ACTE-10']);
        $this->api('POST', '/public/pupils/without-birth-act/transmissions', 'dir1', 't1', 'e1', ['quarter' => '2026-T4']);
        [, $history] = $this->api('GET', '/public/pupils/without-birth-act/transmissions', 'dir1', 't1', 'e1');
        self::assertCount(1, $history['data'], 'Une liste par école et par trimestre.');
        self::assertSame(0, $history['data'][0]['pupil_count']);
        self::assertSame(422, $this->code('POST', '/public/pupils/without-birth-act/transmissions', 'dir1', 't1', 'e1', ['quarter' => '2026-T9']));
    }

    public function testUnEnseignantNeVoitQueLesElevesDeSaClasse(): void
    {
        $mine = $this->pupil($this->classA, 'SYN-Mine');
        $other = $this->pupil($this->classB, 'SYN-Autre');
        $this->teacher('maitre', 't1', 'e1', $this->classA, 'public_schools.pupils.read');

        [$ok, $shown] = $this->api('GET', '/public/pupils/' . $mine, 'maitre', 't1', 'e1');
        self::assertSame(200, $ok->getStatusCode());
        self::assertArrayNotHasKey('situations', $shown['data'], 'Les situations particulières ne sont lues que par la direction.');
        self::assertSame(404, $this->code('GET', '/public/pupils/' . $other, 'maitre', 't1', 'e1'));
        self::assertSame(404, $this->code('GET', '/public/pupils/without-birth-act', 'maitre', 't1', 'e1'));
        self::assertSame(403, $this->code('PATCH', '/public/pupils/' . $mine, 'maitre', 't1', 'e1', ['home_language' => 'fr']));
    }

    public function testSupervisionTerritorialeNominatifAvecFinaliteJournalisee(): void
    {
        $id = $this->pupil($this->classA, 'SYN-Mbarga');
        $this->api('PATCH', '/public/pupils/' . $id, 'dir1', 't1', 'e1', ['is_orphan' => true]);
        $this->supervisor('insp', 't1', 'e1', 'A1', 'pub_district_inspector', 'public_schools.pupils.read', 'public_schools.pupils.named.read');
        $this->supervisor('delegue', 't1', 'e1', 'R1', 'pub_regional_delegate', 'public_schools.pupils.read', 'public_schools.pupils.named.read');

        self::assertSame(422, $this->code('GET', '/public/pupils/' . $id, 'insp', 't1', 'e1'), 'Finalité obligatoire.');
        self::assertSame(422, $this->code('GET', '/public/pupils/' . $id . '?purpose=curiosite', 'insp', 't1', 'e1'));
        self::assertSame([], $this->journal->entries);

        [$read, $json] = $this->api('GET', '/public/pupils/' . $id . '?purpose=control', 'insp', 't1', 'e1');
        self::assertSame(200, $read->getStatusCode());
        self::assertSame('SYN-Mbarga', $json['data']['last_name']);
        self::assertArrayNotHasKey('situations', $json['data']);
        self::assertSame([['tenant' => 't1', 'establishment' => 'e1', 'actor' => 'insp', 'type' => 'pupil', 'id' => $id, 'purpose' => 'control']], $this->journal->entries);

        self::assertSame(403, $this->code('GET', '/public/pupils/' . $id . '?purpose=control', 'delegue', 't1', 'e1'), 'Région : agrégats seuls.');
        self::assertCount(1, $this->journal->entries);
    }

    public function testInspecteurDUnAutreArrondissementNeVoitRien(): void
    {
        $id = $this->pupil($this->classA, 'SYN-Mbarga');
        // L'inspecteur de l'arrondissement A2 n'est pas rattaché à e1 (arrondissement A1) : le périmètre est refusé même si Identity le rendait.
        $this->supervisor('insp2', 't1', 'e1', 'A2', 'pub_district_inspector', 'public_schools.pupils.read', 'public_schools.pupils.named.read');

        self::assertSame(404, $this->code('GET', '/public/pupils/' . $id . '?purpose=control', 'insp2', 't1', 'e1'));
        self::assertSame([], $this->journal->entries);
    }

    public function testIsolationEntreClientsEtEntreEcoles(): void
    {
        $id = $this->pupil($this->classA, 'SYN-Mbarga');

        self::assertSame(404, $this->code('GET', '/public/pupils/' . $id, 'dir9', 't2', 'e9'), 'Autre client.');
        self::assertSame(404, $this->code('GET', '/public/pupils/' . $id, 'dir3', 't1', 'e3'), 'Autre école du même client.');
        self::assertSame(404, $this->code('PATCH', '/public/pupils/' . $id, 'dir3', 't1', 'e3', ['home_language' => 'fr']));
        self::assertSame(404, $this->code('PATCH', '/public/pupils/' . $id, 'dir9', 't2', 'e9', ['home_language' => 'fr']));
        [, $listOther] = $this->api('GET', '/public/pupils/without-birth-act', 'dir9', 't2', 'e9');
        self::assertSame([], $listOther['data']);
    }
}
