<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use Tests\Support\People\PeopleTestCase;

/** US3 : une personne existe peut-être déjà dans le groupe (nom, date de naissance, téléphone). */
final class DuplicateDetectionTest extends PeopleTestCase
{
    private const NADIA = ['first_name' => 'Nadia', 'last_name' => 'Foka', 'birth_date' => '2010-05-14', 'phone' => '+237677123456'];

    protected function setUp(): void
    {
        $this->bootPeople();
    }

    public function testTroisCriteresSurTroisRenvoient409AvecLaFicheExistante(): void
    {
        $existing = $this->api('POST', '/people', body: self::NADIA)[1]['data'];
        $this->api('POST', '/people/' . $existing['id'] . '/schooling-history', body: ['school_name' => 'Collège Les Palmiers', 'level_label' => 'Tle D', 'year_label' => '2025-2026']);

        [$response, $body] = $this->api('POST', '/people', body: ['phone' => '677 12 34 56'] + self::NADIA);

        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('application/problem+json', $response->getHeaderLine('Content-Type'));
        self::assertSame($existing['id'], $body['duplicates'][0]['person']['id']);
        self::assertSame(3, $body['duplicates'][0]['criteria_matched']);
        self::assertSame('3 critères sur 3', $body['duplicates'][0]['label']);
        self::assertSame(['Ancien(ne) élève de Collège Les Palmiers (Tle D, 2025-2026) · même téléphone'], $body['duplicates'][0]['history']);
        self::assertArrayNotHasKey('tenant_id', $body['duplicates'][0]['person']);
    }

    public function testDeuxCriteresSuffisentSiLeNomConcorde(): void
    {
        $this->api('POST', '/people', body: self::NADIA);

        [$response, $body] = $this->api('POST', '/people', body: ['first_name' => 'NADIA', 'last_name' => 'foka', 'birth_date' => '2010-05-14']);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame(2, $body['duplicates'][0]['criteria_matched']);
    }

    public function testLeSeulNomOuUnHomonymeSansAutreCritereNeBloquePas(): void
    {
        $this->api('POST', '/people', body: self::NADIA);

        self::assertSame(201, $this->code('POST', '/people', body: ['first_name' => 'Nadia', 'last_name' => 'Foka', 'birth_date' => '2012-01-01', 'phone' => '699000111']));
        self::assertSame(201, $this->code('POST', '/people', body: ['first_name' => 'Paul', 'last_name' => 'Foka', 'birth_date' => '2010-05-14', 'phone' => '677123456']));
    }

    public function testPersonneDifferenteConfirmeeEstCreeEtJournalisee(): void
    {
        $this->api('POST', '/people', body: self::NADIA);

        [$response, $body] = $this->api('POST', '/people', body: self::NADIA + ['confirm_different' => true]);

        self::assertSame(201, $response->getStatusCode());
        $actions = $this->connections()->pdo()->query("SELECT action FROM audit_log WHERE object_id = '" . $body['data']['id'] . "'")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertContains('person.duplicate_overridden', $actions);
    }

    public function testUneFicheAutreClientNEstJamaisUnDoublon(): void
    {
        $this->person('Nadia', 'Foka', self::NADIA, 't2');

        self::assertSame(201, $this->code('POST', '/people', body: self::NADIA));
    }

    public function testRechercheDeDoublonsAvantSaisie(): void
    {
        $existing = $this->api('POST', '/people', body: self::NADIA)[1]['data'];

        [, $body] = $this->api('GET', '/people/duplicates?first_name=Nadia&last_name=Foka&phone=677123456');
        self::assertSame($existing['id'], $body['data'][0]['person']['id']);

        [, $none] = $this->api('GET', '/people/duplicates?first_name=Autre&last_name=Personne&phone=600000000');
        self::assertSame([], $none['data']);
    }

    public function testLeMatriculeNationalEstUniqueParClient(): void
    {
        $this->api('POST', '/people', body: ['first_name' => 'A', 'last_name' => 'B', 'national_id' => 'CM2026-0001']);

        self::assertSame(409, $this->code('POST', '/people', body: ['first_name' => 'C', 'last_name' => 'D', 'national_id' => 'CM2026-0001']));
        self::assertSame(201, $this->code('POST', '/people', 'admin', 't1', 'e1', ['first_name' => 'C', 'last_name' => 'D', 'national_id' => 'CM2026-0002']));
    }

    public function testChampsInconnusEtDonneesInvalidesSontRefuses(): void
    {
        [$response, $body] = $this->api('POST', '/people', body: ['first_name' => 'A', 'last_name' => 'B', 'tenant_id' => 't2', 'birth_date' => '2999-01-01']);

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('tenant_id', $body['errors']);
        self::assertArrayHasKey('birth_date', $body['errors']);
    }
}
