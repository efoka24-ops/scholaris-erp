<?php

declare(strict_types=1);

namespace Tests\Isolation;

use Tests\Support\People\PeopleTestCase;

/** Aucune donnée de personnes ne franchit la frontière entre deux clients. */
final class PeopleIsolationTest extends PeopleTestCase
{
    /** @var array<string, mixed> */
    private array $nadia = [];

    protected function setUp(): void
    {
        $this->bootPeople();
        $this->nadia = $this->person('Nadia', 'Foka', ['birth_date' => '2010-05-14', 'phone' => '677123456']);
        // « mallory » est gestionnaire d'un autre client (t2), avec accès à un établissement du même nom.
        $this->access->grant('mallory', 'e1');
        $this->permissions->grant('mallory', 'people.read', 'people.manage', 'people.health.read');
    }

    public function testUnGestionnaireDUnAutreClientNeVoitRien(): void
    {
        $id = (string) $this->nadia['id'];

        foreach ([
            ['GET', '/people/' . $id], ['PATCH', '/people/' . $id], ['DELETE', '/people/' . $id],
            ['GET', '/people/' . $id . '/relations'], ['GET', '/people/' . $id . '/documents'], ['GET', '/people/' . $id . '/health'],
            ['GET', '/people/' . $id . '/contacts'], ['GET', '/people/' . $id . '/identifiers'], ['GET', '/people/' . $id . '/schooling-history'],
            ['GET', '/people/' . $id . '/enrollments'], ['GET', '/consents?filter[person_id]=' . $id],
        ] as [$method, $path]) {
            self::assertSame(404, $this->code($method, $path, 'mallory', 't2', 'e1', $method === 'PATCH' ? ['birth_place' => 'x'] : null), $method . ' ' . $path);
        }
        self::assertSame([], $this->api('GET', '/people', 'mallory', 't2')[1]['data']);
        self::assertSame([], $this->api('GET', '/people/duplicates?first_name=Nadia&last_name=Foka&phone=677123456', 'mallory', 't2')[1]['data']);
    }

    public function testLesEcrituresInterClientsSontRefusees(): void
    {
        $mine = $this->person('Paul', 'Ndi', [], 't2');
        $id = (string) $this->nadia['id'];

        self::assertSame(422, $this->code('POST', '/people/' . $mine['id'] . '/relations', 'mallory', 't2', 'e1', ['related_person_id' => $id, 'kind' => 'parent']));
        self::assertSame(404, $this->code('POST', '/people/' . $id . '/relations', 'mallory', 't2', 'e1', ['related_person_id' => $mine['id'], 'kind' => 'parent']));
        self::assertSame(404, $this->code('POST', '/consents', 'mallory', 't2', 'e1', ['person_id' => $id, 'purpose' => 'photo']));
        self::assertSame(404, $this->code('POST', '/enrollments', 'mallory', 't2', 'e1', ['person_id' => $id]));
        self::assertSame('0', (string) $this->connections()->pdo()->query('SELECT COUNT(*) FROM person_relations')->fetchColumn());
        self::assertSame('0', (string) $this->connections()->pdo()->query('SELECT COUNT(*) FROM consents')->fetchColumn());
    }

    public function testUneInscriptionEtUnLienDeTelechargementSontCloisonnes(): void
    {
        $enrollment = $this->api('POST', '/enrollments', body: ['person_id' => $this->nadia['id']])[1]['data'];
        $document = $this->upload('/people/' . $this->nadia['id'] . '/documents', $this->pdfContent(), ['kind' => 'other'])[1]['data'];

        self::assertSame(404, $this->code('GET', '/enrollments/' . $enrollment['id'], 'mallory', 't2'));
        self::assertSame(404, $this->code('POST', '/enrollments/' . $enrollment['id'] . '/validate', 'mallory', 't2'));
        self::assertSame(404, $this->code('GET', '/people/' . $this->nadia['id'] . '/documents/' . $document['id'] . '/link', 'mallory', 't2'));
        self::assertSame([], $this->api('GET', '/enrollments', 'mallory', 't2')[1]['data']);
    }

    public function testLeMemeMatriculeNationalPeutExisterChezDeuxClients(): void
    {
        $this->person('A', 'B', ['national_id' => 'CM2026-1111'], 't2');

        self::assertSame(201, $this->code('POST', '/people', body: ['first_name' => 'C', 'last_name' => 'D', 'national_id' => 'CM2026-1111']));
    }
}
