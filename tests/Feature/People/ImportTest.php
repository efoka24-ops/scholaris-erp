<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use Tests\Support\People\PeopleTestCase;

/** Import CSV : rapport d'erreurs ligne par ligne, rien d'enregistré en cas d'erreur bloquante, option « valider seulement ». */
final class ImportTest extends PeopleTestCase
{
    private const HEADER = "first_name;last_name;birth_date;gender;phone;nationality\n";

    protected function setUp(): void
    {
        $this->bootPeople();
    }

    private function total(): int
    {
        return (int) $this->connections()->pdo()->query('SELECT COUNT(*) FROM people')->fetchColumn();
    }

    public function testImportValideCreeLesPersonnes(): void
    {
        $csv = self::HEADER . "Nadia;Foka;2010-05-14;F;677123456;Camerounaise\nPaul;Ndi;2011-02-01;masculin;699000111;\n";

        [$response, $body] = $this->api('POST', '/people/import', body: ['csv' => $csv]);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(2, $body['data']['created']);
        self::assertSame([], $body['data']['errors']);
        self::assertSame(2, $this->total());
        self::assertSame('female', $this->connections()->pdo()->query("SELECT gender FROM people WHERE first_name = 'Nadia'")->fetchColumn());
    }

    public function testUneErreurBloquanteNEnregistreRien(): void
    {
        $csv = self::HEADER
            . "Nadia;Foka;2010-05-14;F;677123456;\n"
            . "Paul;;2011-02-01;M;699000111;\n"
            . "Luc;Bika;2031-01-01;M;abc;\n"
            . "Nadia;Foka;2010-05-14;F;677123456;\n";

        [$response, $body] = $this->api('POST', '/people/import', body: ['csv' => $csv]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(0, $this->total(), 'Rien n\'est enregistré.');
        $errors = $body['report']['errors'];
        self::assertSame([3, 4, 4, 5], array_column($errors, 'line'));
        self::assertSame(['last_name', 'birth_date', 'phone', null], array_column($errors, 'field'));
        self::assertStringContainsString('Doublon', $errors[3]['message']);
        self::assertSame(0, $body['report']['created']);
    }

    public function testValiderSeulementNeCreeRien(): void
    {
        $good = self::HEADER . "Nadia;Foka;2010-05-14;F;677123456;\n";
        [$response, $body] = $this->api('POST', '/people/import', body: ['csv' => $good, 'validate_only' => true]);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $body['data']['valid']);
        self::assertSame(0, $body['data']['created']);
        self::assertSame(0, $this->total());

        [$response, $body] = $this->api('POST', '/people/import', body: ['csv' => self::HEADER . ";X;;;;\n", 'validate_only' => true]);
        self::assertSame(200, $response->getStatusCode(), 'Le rapport est le résultat attendu.');
        self::assertSame('first_name', $body['data']['errors'][0]['field']);
    }

    public function testDoublonAvecUneFicheExistanteSaufConfirmation(): void
    {
        $this->person('Nadia', 'Foka', ['birth_date' => '2010-05-14', 'phone' => '+237677123456']);

        [$refused, $body] = $this->api('POST', '/people/import', body: ['csv' => self::HEADER . "Nadia;Foka;2010-05-14;F;677 12 34 56;\n"]);
        self::assertSame(422, $refused->getStatusCode());
        self::assertStringContainsString('3 critères sur 3', $body['report']['errors'][0]['message']);

        $csv = "first_name;last_name;birth_date;phone;confirm_different\nNadia;Foka;2010-05-14;677123456;oui\n";
        self::assertSame(201, $this->code('POST', '/people/import', body: ['csv' => $csv]));
        self::assertSame(2, $this->total());
    }

    public function testColonnesInconnuesEtFichierMultipartEnWindows1252(): void
    {
        [$response, $body] = $this->api('POST', '/people/import', body: ['csv' => "first_name,last_name,tenant_id\nA,B,t2\n"]);
        self::assertSame(422, $response->getStatusCode());
        self::assertSame('tenant_id', $body['report']['errors'][0]['field']);

        $latin1 = mb_convert_encoding("first_name;last_name\nÉlodie;Mbarga\n", 'Windows-1252', 'UTF-8');
        [$upload] = $this->upload('/people/import', $latin1);
        self::assertSame(201, $upload->getStatusCode());
        self::assertSame('Élodie', $this->connections()->pdo()->query('SELECT first_name FROM people')->fetchColumn());
    }

    public function testImportReserveAuxGestionnaires(): void
    {
        $this->access->grant('lecteur', 'e1');
        $this->permissions->grant('lecteur', 'people.read');

        self::assertSame(403, $this->code('POST', '/people/import', 'lecteur', body: ['csv' => self::HEADER]));
    }
}
