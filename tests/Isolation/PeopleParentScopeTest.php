<?php

declare(strict_types=1);

namespace Tests\Isolation;

use Tests\Support\People\PeopleTestCase;

/** Un parent ne voit que ses apprenants, un enseignant que ses classes ; aucun droit d'écriture sans people.manage. */
final class PeopleParentScopeTest extends PeopleTestCase
{
    /** @var array<string, array<string, mixed>> */
    private array $people = [];

    protected function setUp(): void
    {
        $this->bootPeople();
        $this->people = [
            'yann' => $this->person('Yann', 'Foka', ['birth_date' => '2012-01-01']),
            'nadia' => $this->person('Nadia', 'Foka', ['birth_date' => '2010-05-14']),
            'autre' => $this->person('Paul', 'Ndi', ['birth_date' => '2011-03-03']),
            'jean' => $this->person('Jean', 'Foka'),
            'prof' => $this->person('Mme', 'Ateba'),
        ];
        foreach (['yann', 'nadia'] as $child) {
            $this->api('POST', '/people/' . $this->people[$child]['id'] . '/relations', body: ['related_person_id' => $this->people['jean']['id'], 'kind' => 'financial_guarantor']);
        }
        $this->api('POST', '/people/' . $this->people['autre']['id'] . '/relations', body: ['related_person_id' => $this->people['jean']['id'], 'kind' => 'authorized_pickup']);
        $this->account('parent', (string) $this->people['jean']['id']);
        $this->account('prof-user', (string) $this->people['prof']['id']);
        $this->account('inconnu', (string) $this->person('Sans', 'Lien')['id']);
        $this->teaching->classes[(string) $this->people['prof']['id']] = [(string) $this->people['autre']['id']];
    }

    /** @return list<string> */
    private function listed(string $user): array
    {
        return array_column($this->api('GET', '/people', $user)[1]['data'], 'first_name');
    }

    public function testUnParentVoitTousSesEnfantsEtRienDAutre(): void
    {
        $names = $this->listed('parent');
        sort($names);

        self::assertSame(['Jean', 'Nadia', 'Yann'], $names, 'Lui-même et ses enfants, pas Paul (simple personne autorisée à récupérer).');
        self::assertSame(200, $this->code('GET', '/people/' . $this->people['nadia']['id'], 'parent'));
        self::assertSame(200, $this->code('GET', '/people/' . $this->people['yann']['id'] . '/relations', 'parent'));
        self::assertSame(404, $this->code('GET', '/people/' . $this->people['autre']['id'], 'parent'));
        self::assertSame(404, $this->code('GET', '/people/' . $this->people['autre']['id'] . '/documents', 'parent'));
    }

    public function testUnEnseignantNeVoitQueSesClasses(): void
    {
        self::assertSame(['Mme', 'Paul'], $this->sorted($this->listed('prof-user')));
        self::assertSame(200, $this->code('GET', '/people/' . $this->people['autre']['id'], 'prof-user'));
        self::assertSame(404, $this->code('GET', '/people/' . $this->people['yann']['id'], 'prof-user'));
    }

    /**
     * @param list<string> $names
     * @return list<string>
     */
    private function sorted(array $names): array
    {
        sort($names);

        return $names;
    }

    public function testUnCompteSansLienNeVoitQueSaFiche(): void
    {
        self::assertSame(['Sans'], $this->listed('inconnu'));
        self::assertSame(404, $this->code('GET', '/people/' . $this->people['yann']['id'], 'inconnu'));
    }

    public function testLesInscriptionsDesEnfantsSontVisiblesDuParentSeulement(): void
    {
        $draft = $this->api('POST', '/enrollments', body: ['person_id' => $this->people['yann']['id']])[1]['data'];
        $this->api('POST', '/enrollments', body: ['person_id' => $this->people['autre']['id']]);

        self::assertSame([(string) $draft['id']], array_column($this->api('GET', '/enrollments', 'parent')[1]['data'], 'id'));
        self::assertSame(200, $this->code('GET', '/enrollments/' . $draft['id'], 'parent'));
        self::assertSame(200, $this->code('GET', '/people/' . $this->people['yann']['id'] . '/enrollments', 'parent'));
        self::assertSame(404, $this->code('GET', '/people/' . $this->people['autre']['id'] . '/enrollments', 'parent'));
    }

    public function testAucuneEcritureNiSanteSansPermission(): void
    {
        $child = '/people/' . $this->people['yann']['id'];

        self::assertSame(403, $this->code('PATCH', $child, 'parent', body: ['birth_place' => 'x']));
        self::assertSame(403, $this->code('POST', '/people', 'parent', body: ['first_name' => 'A', 'last_name' => 'B']));
        self::assertSame(403, $this->code('POST', $child . '/relations', 'parent', body: ['related_person_id' => $this->people['prof']['id'], 'kind' => 'parent']));
        self::assertSame(403, $this->code('POST', '/enrollments', 'parent', body: ['person_id' => $this->people['yann']['id']]));
        self::assertSame(403, $this->code('GET', $child . '/health', 'parent'));
        self::assertSame(403, $this->code('GET', '/people/duplicates?first_name=A&last_name=B', 'parent'));
    }

    public function testUnParentPeutDonnerLeConsentementDeSonEnfantMineur(): void
    {
        self::assertSame(201, $this->code('POST', '/consents', 'parent', body: ['person_id' => $this->people['yann']['id'], 'purpose' => 'photo']));
        self::assertSame(404, $this->code('POST', '/consents', 'parent', body: ['person_id' => $this->people['autre']['id'], 'purpose' => 'photo']));
        self::assertSame(404, $this->code('POST', '/consents', 'prof-user', body: ['person_id' => $this->people['autre']['id'], 'purpose' => 'photo']), 'Un enseignant ne donne pas de consentement.');
    }
}
