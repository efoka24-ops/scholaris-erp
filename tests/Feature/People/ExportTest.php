<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use Tests\Support\Exports\FetchesCsv;
use Tests\Support\Exports\ReadsXlsx;
use Tests\Support\People\PeopleTestCase;

/** Exports CSV des personnes et des inscriptions : format, filtres, isolation client / établissement. */
final class ExportTest extends PeopleTestCase
{
    use FetchesCsv;
    use ReadsXlsx;

    protected function setUp(): void
    {
        $this->bootPeople();
    }

    /** @return array<string, string> */
    private function as(string $user = 'admin', string $tenant = 't1', string $establishment = 'e1'): array
    {
        return ['Authorization' => 'Bearer ' . $user . ':' . $tenant, 'X-Establishment-Id' => $establishment];
    }

    public function testFormatExcelPourPersonnesEtInscriptions(): void
    {
        $mine = $this->person('Éric', '=SOMME(A1)');
        $this->api('POST', '/enrollments', body: ['person_id' => $mine['id']]);

        $people = $this->xlsxRows('/people/export?format=xlsx', $this->as());
        self::assertCount(2, $people);
        self::assertContains('Éric', $people[1]);
        self::assertContains("'=SOMME(A1)", $people[1], 'Formule neutralisée en Excel.');
        self::assertCount(2, $this->xlsxRows('/enrollments/export?format=xlsx', $this->as()));
        self::assertSame(422, $this->fetch('/people/export?format=pdf', $this->as())->getStatusCode());
    }
    public function testExportPersonnesEnCsvUtf8AvecBomEtFiltre(): void
    {
        $this->person('Nadia', 'Foka');
        $this->person('Éric', '=SOMME(A1)');
        $this->person('Autre', 'Client', [], 't2');

        $rows = $this->csvRows('/people/export', $this->as());

        self::assertSame(['Identifiant', 'Prénom', 'Nom'], array_slice($rows[0], 0, 3));
        self::assertCount(3, $rows);
        $csv = $this->csvText('/people/export', $this->as());
        self::assertStringContainsString('Nadia;Foka', $csv);
        self::assertStringContainsString("'=SOMME(A1)", $csv, 'Les formules sont neutralisées.');
        self::assertStringNotContainsString('Client', $csv, 'Le client t2 est invisible.');

        $filtered = $this->csvText('/people/export?filter[last_name]=foka', $this->as());
        self::assertStringContainsString('Nadia', $filtered);
        self::assertStringNotContainsString('SOMME', $filtered);
        self::assertSame(422, $this->fetch('/people/export?filter[inconnu]=x', $this->as())->getStatusCode());
    }

    public function testExportPersonnesParcourtToutesLesPages(): void
    {
        for ($i = 0; $i < 205; ++$i) {
            $this->person('Prenom' . $i, 'Nom');
        }

        self::assertCount(206, $this->csvRows('/people/export?per_page=10', $this->as()), 'En-tête + 205 personnes.');
    }

    public function testExportInscriptionsLimiteAuClientEtALEtablissement(): void
    {
        $mine = $this->person('Nadia', 'Foka');
        $other = $this->person('Paul', 'Voisin', [], 't2');
        $this->api('POST', '/enrollments', body: ['person_id' => $mine['id']]);
        $this->access->grant('admin2', 'e9');
        $this->permissions->grant('admin2', 'people.read', 'people.manage');

        $rows = $this->csvRows('/enrollments/export?filter[status]=draft', $this->as());

        self::assertSame(['Identifiant', 'Personne'], array_slice($rows[0], 0, 2));
        self::assertCount(2, $rows);
        self::assertSame($mine['id'], $rows[1][1]);
        self::assertStringNotContainsString($other['id'], $this->csvText('/enrollments/export', $this->as()));
        self::assertStringNotContainsString($mine['id'], $this->csvText('/enrollments/export', $this->as('admin2', 't1', 'e9')), 'Autre établissement.');
        self::assertStringNotContainsString($mine['id'], $this->csvText('/enrollments/export', $this->as('admin', 't2')), 'Autre client.');
    }
}
