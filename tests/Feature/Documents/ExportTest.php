<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use Tests\Support\Documents\DocumentsTestCase;
use Tests\Support\Exports\ReadsXlsx;

/** Exports CSV / Excel du registre, des lots et des demandes de documents : filtres, droits, isolation, format. */
final class ExportTest extends DocumentsTestCase
{
    use ReadsXlsx;

    protected function setUp(): void
    {
        $this->bootDocuments();
        $this->issueViaApi('p1', key: 'cle-emission-0001');
        $this->issueViaApi('p2', key: 'cle-emission-0002');
        $this->api('POST', '/document-batches', 'alice', 't1', 'e1', ['type' => 'attestation_simple', 'items' => [['subject_person_id' => 'p1', 'data' => []], ['subject_person_id' => 'p2', 'data' => []]]]);
        $this->api('POST', '/document-requests', 'alice', 't1', 'e1', ['type' => 'attestation_simple', 'subject_person_id' => 'p2', 'note' => 'Pour la bourse']);
        $this->api('POST', '/document-requests', 'alice', 't1', 'e1', ['type' => 'attestation_simple', 'subject_person_id' => 'p1']);
        $this->access->grant('carol', 'e1');
    }

    /** @return list<list<string>> */
    private function csv(string $path, string $user = 'alice', string $tenant = 't1', string $establishment = 'e1'): array
    {
        [$response] = $this->api('GET', $path, $user, $tenant, $establishment);
        self::assertSame(200, $response->getStatusCode(), $path . ' ' . $response->getBody());
        $body = (string) $response->getBody();
        self::assertStringStartsWith("\xEF\xBB\xBF", $body);
        self::assertStringStartsWith('text/csv; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('attachment', $response->getHeaderLine('Content-Disposition'));

        return array_map(static fn (string $line): array => str_getcsv($line, ';', '"', ''), explode("\n", trim(substr($body, 3))));
    }

    public function testExportDesLots(): void
    {
        $rows = $this->csv('/document-batches/export');
        self::assertCount(2, $rows);
        self::assertSame(['Identifiant', 'Type', 'Langue', 'Statut'], array_slice($rows[0], 0, 4));
        self::assertSame('attestation_simple', $rows[1][1]);
        self::assertSame('2', $rows[1][4]);
    }

    public function testExportDesDemandesAvecFiltre(): void
    {
        $rows = $this->csv('/document-requests/export');
        self::assertCount(3, $rows);
        self::assertSame(['Identifiant', 'Type', 'Titulaire', 'Demandé par', 'Note'], array_slice($rows[0], 0, 5));
        self::assertContains('Pour la bourse', array_column($rows, 4));

        self::assertCount(3, $this->csv('/document-requests/export?filter[status]=pending'));
        self::assertCount(1, $this->csv('/document-requests/export?filter[status]=rejected'));
        self::assertSame(422, $this->api('GET', '/document-requests/export?filter[x]=1', 'alice', 't1')[0]->getStatusCode());
    }

    public function testSansDroitDeLectureLesExportsSontRefuses(): void
    {
        foreach (['/documents/export', '/document-batches/export', '/document-requests/export'] as $path) {
            self::assertSame(403, $this->api('GET', $path, 'carol', 't1')[0]->getStatusCode(), $path);
        }
    }

    public function testFormatExcelSurLesTroisExports(): void
    {
        $headers = ['Authorization' => 'Bearer alice:t1', 'X-Establishment-Id' => 'e1'];
        foreach (['/documents/export' => 3, '/document-batches/export' => 2, '/document-requests/export' => 3] as $path => $count) {
            self::assertCount($count, $this->xlsxRows($path . '?format=xlsx', $headers), $path);
        }
        self::assertSame(422, $this->api('GET', '/documents/export?format=pdf', 'alice', 't1')[0]->getStatusCode());
    }

    public function testIsolationEntreEtablissementsEtEntreClients(): void
    {
        foreach (['/documents/export', '/document-batches/export', '/document-requests/export'] as $path) {
            self::assertGreaterThan(1, count($this->csv($path)), $path);
            self::assertCount(1, $this->csv($path, 'alice', 't1', 'e2'), $path . ' : autre établissement');
            self::assertCount(1, $this->csv($path, 'bob', 't2', 'e3'), $path . ' : autre client');
        }
    }
}
