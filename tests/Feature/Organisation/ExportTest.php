<?php

declare(strict_types=1);

namespace Tests\Feature\Organisation;

use Psr\Http\Message\ResponseInterface;
use Tests\Support\Exports\ReadsXlsx;
use Tests\Support\Organisation\OrganisationTestCase;

/** Exports CSV / Excel de l'organisation : établissements, unités, années, périodes, structures, salles, autorisations. */
final class ExportTest extends OrganisationTestCase
{
    use ReadsXlsx;

    private const PATHS = ['/org-units', '/academic-years', '/periods', '/structures', '/authorizations', '/rooms'];

    protected function setUp(): void
    {
        $this->bootOrganisation();
        $this->seedEstablishment('t1', 'e1', 'Lycée', 'secondary', 'alice', 'reader', 'nobody');
        $this->seedEstablishment('t1', 'e2', 'Collège', 'secondary', 'alice');
        $this->seedEstablishment('t2', 'e3', 'Autre école', 'secondary', 'bob');
        $this->grantAll('alice');
        $this->grantAll('bob');
        $this->permissions->grant('reader', 'organisation.read');

        $unit = $this->api('POST', '/org-units', 'alice', 't1', 'e1', ['type' => 'department', 'name' => 'Sciences'])[1]['data']['id'];
        $this->api('POST', '/org-units', 'alice', 't1', 'e1', ['type' => 'section', 'name' => 'Mathématiques', 'parent_id' => $unit]);
        $year = $this->api('POST', '/academic-years', 'alice', 't1', 'e1', ['label' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30'])[1]['data']['id'];
        $this->api('POST', '/periods', 'alice', 't1', 'e1', ['academic_year_id' => $year, 'kind' => 'trimester', 'ordinal' => 1, 'starts_on' => '2026-09-01', 'ends_on' => '2026-12-18']);
        $this->api('POST', '/structures', 'alice', 't1', 'e1', ['kind' => 'class', 'name' => '2nde C', 'capacity' => 55, 'org_unit_id' => $unit]);
        $this->api('POST', '/rooms', 'alice', 't1', 'e1', ['name' => 'Salle A', 'capacity' => 30, 'equipment' => ['projecteur', 'tableau']]);
        $this->api('POST', '/rooms', 'alice', 't1', 'e1', ['name' => '=Salle B', 'capacity' => 40]);
        $this->api('POST', '/rooms', 'alice', 't1', 'e2', ['name' => 'Salle E2', 'capacity' => 20]);
        $this->api('POST', '/rooms', 'bob', 't2', 'e3', ['name' => 'Salle autre client', 'capacity' => 20]);
    }

    private function get(string $path, string $user, string $tenant, ?string $establishment = 'e1'): ResponseInterface
    {
        $headers = ['Authorization' => 'Bearer ' . $user . ':' . $tenant];
        if ($establishment !== null) {
            $headers['X-Establishment-Id'] = $establishment;
        }

        return $this->call('GET', '/api/v1' . $path, $headers);
    }

    private function httpStatus(string $path, string $user, string $tenant, ?string $establishment = 'e1'): int
    {
        return $this->get($path, $user, $tenant, $establishment)->getStatusCode();
    }

    /** @return list<list<string>> */
    private function csv(string $path, string $user = 'alice', string $tenant = 't1', ?string $establishment = 'e1'): array
    {
        $response = $this->get($path, $user, $tenant, $establishment);
        self::assertSame(200, $response->getStatusCode(), $path . ' ' . $response->getBody());
        $body = (string) $response->getBody();
        self::assertStringStartsWith("\xEF\xBB\xBF", $body);
        self::assertStringStartsWith('text/csv; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('attachment', $response->getHeaderLine('Content-Disposition'));

        return array_map(static fn (string $line): array => str_getcsv($line, ';', '"', ''), explode("\n", trim(substr($body, 3))));
    }

    public function testExportDesUnitesAvecFiltre(): void
    {
        $all = $this->csv('/org-units/export');
        self::assertCount(3, $all);
        self::assertSame(['Identifiant', 'Parent', 'Type', 'Nom'], array_slice($all[0], 0, 4));
        self::assertContains('Mathématiques', array_column($all, 3));
        self::assertCount(2, $this->csv('/org-units/export?filter[type]=section'));
        self::assertSame(['Mathématiques', 'Sciences'], array_column(array_slice($this->csv('/org-units/export?sort=name'), 1), 3));
        self::assertSame(422, $this->httpStatus('/org-units/export?filter[name]=Maths', 'alice', 't1'));
    }

    public function testExportDesAnneesPeriodesStructuresEtSalles(): void
    {
        $years = $this->csv('/academic-years/export');
        self::assertCount(2, $years);
        self::assertContains('2026-2027', array_column($years, 1));

        $periods = $this->csv('/periods/export');
        self::assertCount(2, $periods);
        self::assertContains('trimester', $periods[1]);

        $structures = $this->csv('/structures/export?filter[kind]=class');
        self::assertCount(2, $structures);
        self::assertContains('55', $structures[1]);

        $rooms = $this->csv('/rooms/export');
        self::assertCount(3, $rooms);
        self::assertContains('projecteur, tableau', array_column($rooms, 3));
        self::assertContains("'=Salle B", array_column($rooms, 1), 'Formule neutralisée.');
        self::assertCount(2, $this->csv('/rooms/export?filter[name]=Salle A'));
    }

    public function testExportDesEtablissementsSelonLesDroits(): void
    {
        $all = $this->csv('/establishments/export', 'alice', 't1', null);
        self::assertCount(3, $all);
        self::assertSame(['Identifiant', 'Nom', 'Type', 'Modules activés'], array_slice($all[0], 0, 4));
        self::assertContains('Collège', array_column($all, 1));
        self::assertStringContainsString('people', implode(' ', array_column($all, 3)));
        self::assertCount(3, $this->csv('/establishments/export?filter[type]=secondary&per_page=1', 'alice', 't1', null), 'Les pages sont parcourues jusqu\'au bout.');
    }

    public function testLaLectureEstExigeeEtLesFiltresSontControles(): void
    {
        foreach (self::PATHS as $path) {
            self::assertSame(403, $this->httpStatus($path . '/export', 'nobody', 't1'), $path);
            self::assertSame(200, $this->httpStatus($path . '/export', 'reader', 't1'), $path . ' en lecture seule');
            self::assertSame(422, $this->httpStatus($path . '/export?filter[inconnu]=1', 'alice', 't1'), $path);
        }
        self::assertSame(403, $this->httpStatus('/establishments/export', 'nobody', 't1', null));
    }

    public function testFormatExcelSurTousLesExportsDuModule(): void
    {
        $headers = ['Authorization' => 'Bearer alice:t1', 'X-Establishment-Id' => 'e1'];
        $expected = ['/org-units' => 3, '/academic-years' => 2, '/periods' => 2, '/structures' => 2, '/authorizations' => 1, '/rooms' => 3];
        foreach ($expected as $path => $count) {
            self::assertCount($count, $this->xlsxRows($path . '/export?format=xlsx', $headers), $path);
        }
        $rooms = $this->xlsxRows('/rooms/export?format=xlsx', $headers);
        self::assertContains("'=Salle B", array_column($rooms, 1));
        self::assertContains('Équipements', $rooms[0]);
        self::assertCount(3, $this->xlsxRows('/establishments/export?format=xlsx', ['Authorization' => 'Bearer alice:t1']));
        self::assertSame(422, $this->httpStatus('/rooms/export?format=odt', 'alice', 't1'));
    }

    public function testIsolationEntreEtablissementsEtEntreClients(): void
    {
        $e2 = $this->csv('/rooms/export', 'alice', 't1', 'e2');
        self::assertCount(2, $e2);
        self::assertSame('Salle E2', $e2[1][1], 'L\'autre établissement ne voit que ses salles.');

        $other = $this->csv('/rooms/export', 'bob', 't2', 'e3');
        self::assertCount(2, $other);
        self::assertStringNotContainsString('Salle A', implode(' ', array_column($other, 1)));

        foreach (self::PATHS as $path) {
            self::assertCount($path === '/rooms' ? 2 : 1, $this->csv($path . '/export', 'bob', 't2', 'e3'), $path . ' : le client t2 ne reçoit rien de t1');
        }
        $establishments = $this->csv('/establishments/export', 'bob', 't2', null);
        self::assertCount(2, $establishments);
        self::assertSame('Autre école', $establishments[1][1]);
    }
}
