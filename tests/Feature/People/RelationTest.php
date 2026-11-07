<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use Tests\Support\People\PeopleTestCase;

/** Relations bidirectionnelles cohérentes, identifiants et contacts. */
final class RelationTest extends PeopleTestCase
{
    protected function setUp(): void
    {
        $this->bootPeople();
    }

    public function testUnLienSeLitDesDeuxCotes(): void
    {
        $child = $this->person('Nadia', 'Foka');
        $parent = $this->person('Jean', 'Foka');

        [$response] = $this->api('POST', '/people/' . $child['id'] . '/relations', body: ['related_person_id' => $parent['id'], 'kind' => 'parent']);
        self::assertSame(201, $response->getStatusCode());

        $fromChild = $this->api('GET', '/people/' . $child['id'] . '/relations')[1]['data'];
        $fromParent = $this->api('GET', '/people/' . $parent['id'] . '/relations')[1]['data'];
        self::assertSame(['parent', 'subject', $parent['id']], [$fromChild[0]['kind'], $fromChild[0]['as'], $fromChild[0]['person']['id']]);
        self::assertSame(['parent', 'related', $child['id']], [$fromParent[0]['kind'], $fromParent[0]['as'], $fromParent[0]['person']['id']]);
        self::assertSame($fromChild[0]['id'], $fromParent[0]['id'], 'Une seule ligne pour les deux vues.');
    }

    public function testLiensIncoherentsOuDoublesRefuses(): void
    {
        $child = $this->person('Nadia', 'Foka');
        $parent = $this->person('Jean', 'Foka');
        $link = static fn (array $subject, array $other, string $kind): array => ['path' => '/people/' . $subject['id'] . '/relations', 'body' => ['related_person_id' => $other['id'], 'kind' => $kind]];
        $post = function (array $call): int {
            return $this->code('POST', $call['path'], body: $call['body']);
        };

        self::assertSame(201, $post($link($child, $parent, 'parent')));
        self::assertSame(409, $post($link($child, $parent, 'parent')), 'Doublon.');
        self::assertSame(409, $post($link($parent, $child, 'parent')), 'Réciproque incohérente.');
        self::assertSame(409, $post($link($parent, $child, 'guardian')), 'Réciproque incohérente.');
        self::assertSame(201, $post($link($child, $parent, 'financial_guarantor')), 'Un parent peut aussi être garant financier.');
        self::assertSame(422, $post($link($child, $child, 'emergency')), 'Auto-lien.');
        self::assertSame(422, $this->code('POST', '/people/' . $child['id'] . '/relations', body: ['related_person_id' => $parent['id'], 'kind' => 'cousin']));
    }

    public function testSuppressionDUnLien(): void
    {
        $child = $this->person('Nadia', 'Foka');
        $parent = $this->person('Jean', 'Foka');
        $id = $this->api('POST', '/people/' . $child['id'] . '/relations', body: ['related_person_id' => $parent['id'], 'kind' => 'emergency'])[1]['data']['id'];

        self::assertSame(204, $this->code('DELETE', '/people/' . $parent['id'] . '/relations/' . $id), 'Supprimable depuis l\'un ou l\'autre côté.');
        self::assertSame([], $this->api('GET', '/people/' . $child['id'] . '/relations')[1]['data']);
        self::assertSame(404, $this->code('DELETE', '/people/' . $parent['id'] . '/relations/' . $id));
    }

    public function testIdentifiantsEtContacts(): void
    {
        $person = $this->person('Nadia', 'Foka');
        $base = '/people/' . $person['id'];

        self::assertSame(422, $this->code('POST', $base . '/identifiers', body: ['kind' => 'internal', 'value' => 'MAT-1']), 'Établissement requis.');
        self::assertSame(201, $this->code('POST', $base . '/identifiers', body: ['kind' => 'internal', 'value' => 'MAT-1', 'establishment_id' => 'e1']));
        self::assertSame(409, $this->code('POST', $base . '/identifiers', body: ['kind' => 'internal', 'value' => 'MAT-1', 'establishment_id' => 'e1']));
        self::assertSame(201, $this->code('POST', $base . '/identifiers', body: ['kind' => 'internal', 'value' => 'MAT-1', 'establishment_id' => 'e2']), 'Unique par établissement.');

        self::assertSame(201, $this->code('POST', $base . '/identifiers', body: ['kind' => 'national', 'value' => 'CM2026-0042']));
        self::assertSame('CM2026-0042', $this->api('GET', $base)[1]['data']['national_id'], 'Le matricule national alimente la fiche.');
        $other = $this->person('Paul', 'Ndi');
        self::assertSame(409, $this->code('POST', '/people/' . $other['id'] . '/identifiers', body: ['kind' => 'national', 'value' => 'CM2026-0042']));

        self::assertSame(201, $this->code('POST', $base . '/contacts', body: ['kind' => 'phone', 'value' => '699000111', 'label' => 'Oncle']));
        self::assertCount(1, $this->api('GET', $base . '/contacts')[1]['data']);
        self::assertSame(422, $this->code('POST', $base . '/contacts', body: ['kind' => 'fax', 'value' => '1']));
    }

    public function testRechercheEtSuppressionDUnePersonne(): void
    {
        $person = $this->person('Nadia', 'Foka', ['phone' => '677123456']);
        $this->person('Paul', 'Ndi');

        self::assertCount(1, $this->api('GET', '/people?filter[q]=foka')[1]['data']);
        self::assertCount(1, $this->api('GET', '/people?filter[phone]=677123456')[1]['data']);
        self::assertCount(2, $this->api('GET', '/people')[1]['data']);
        [, $page] = $this->api('GET', '/people?per_page=1');
        self::assertCount(1, $page['data']);
        self::assertNotNull($page['meta']['next_cursor']);
        self::assertCount(1, $this->api('GET', '/people?per_page=1&cursor=' . $page['meta']['next_cursor'])[1]['data']);
        self::assertSame(422, $this->code('GET', '/people?filter[tenant_id]=t2'));

        self::assertSame(200, $this->code('PATCH', '/people/' . $person['id'], body: ['birth_place' => 'Douala']));
        self::assertSame(204, $this->code('DELETE', '/people/' . $person['id']));
        self::assertSame(404, $this->code('GET', '/people/' . $person['id']));
    }

    public function testUnePersonneInscriteNePeutPasEtreSupprimee(): void
    {
        $person = $this->person('Nadia', 'Foka');
        $this->api('POST', '/consents', body: ['person_id' => $person['id'], 'purpose' => 'photo']);

        self::assertSame(409, $this->code('DELETE', '/people/' . $person['id']));
    }
}
