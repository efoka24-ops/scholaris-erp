<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Tests\Support\Audit\AuditTestCase;
use Tests\Support\Exports\ReadsXlsx;

/** Exports CSV / Excel des demandes de personnes concernées et du journal d'audit : droits, filtres, données sensibles, isolation. */
final class ExportTest extends AuditTestCase
{
    use ReadsXlsx;

    protected function setUp(): void
    {
        $this->bootAudit();
        $this->newRequest('access', 'p1');
        $this->newRequest('erasure', 'p2');
        $this->newRequest('access', 'p9', 'bob', 't2');
        // Une entrée avec états avant / après, adresse IP et appareil : rien de tout cela ne doit sortir.
        $this->kernel->container()->get(AuditLog::class)->append('t1', 'alice', 'organisation.updated', 'room', 'r1', ['secret_avant' => 'AVANT-123'], ['secret_apres' => 'APRES-456'], '203.0.113.7', 'Mozilla/5.0 Appareil-Secret');
        $this->permissions->grant('auditor', 'privacy.requests.read');
    }

    /** @return array{0: int, 1: string, 2: \Psr\Http\Message\ResponseInterface} */
    private function raw(string $path, string $user, string $tenant = 't1'): array
    {
        $response = $this->call('GET', '/api/v1' . $path, ['Authorization' => 'Bearer ' . $user . ':' . $tenant]);

        return [$response->getStatusCode(), (string) $response->getBody(), $response];
    }

    /** @return list<list<string>> */
    private function csv(string $path, string $user, string $tenant = 't1'): array
    {
        [$status, $body, $response] = $this->raw($path, $user, $tenant);
        self::assertSame(200, $status, $path . ' ' . $body);
        self::assertStringStartsWith("\xEF\xBB\xBF", $body);
        self::assertStringStartsWith('text/csv; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('attachment', $response->getHeaderLine('Content-Disposition'));

        return array_map(static fn (string $line): array => str_getcsv($line, ';', '"', ''), explode("\n", trim(substr($body, 3))));
    }

    public function testExportDesDemandesDePersonnesConcernees(): void
    {
        $all = $this->csv('/data-requests/export', 'alice');
        self::assertCount(3, $all);
        self::assertSame(['Identifiant', 'Nature', 'Personne', 'Détails', 'Statut'], array_slice($all[0], 0, 5));
        self::assertEqualsCanonicalizing(['access', 'erasure'], array_column(array_slice($all, 1), 1));

        self::assertCount(2, $this->csv('/data-requests/export?filter[kind]=erasure', 'alice'));
        self::assertCount(1, $this->csv('/data-requests/export?filter[status]=refused', 'alice'));
        self::assertSame(422, $this->raw('/data-requests/export?filter[x]=1', 'alice')[0]);
        self::assertSame(200, $this->raw('/data-requests/export', 'root')[0], 'La lecture suffit.');
        self::assertSame(403, $this->raw('/data-requests/export', 'nobody')[0]);
        self::assertSame(401, $this->call('GET', '/api/v1/data-requests/export')->getStatusCode());
    }

    public function testLeJournalDAuditEstReserveALaPermissionDExport(): void
    {
        self::assertSame(403, $this->raw('/audit-log/export', 'alice')[0], 'La gestion des demandes ne suffit pas.');
        self::assertSame(403, $this->raw('/audit-log/export', 'auditor')[0]);
        self::assertSame(403, $this->raw('/audit-log/export', 'nobody')[0]);
        self::assertSame(401, $this->call('GET', '/api/v1/audit-log/export')->getStatusCode());
        self::assertSame(200, $this->raw('/audit-log/export', 'root')[0]);
    }

    public function testLeJournalNExposeAucuneDonneeSensible(): void
    {
        $rows = $this->csv('/audit-log/export', 'root');
        self::assertSame(['Rang', 'Date', 'Auteur', 'Action', 'Type d\'objet', 'Objet'], $rows[0]);
        self::assertGreaterThanOrEqual(3, count($rows));
        self::assertContains('privacy.request.received', array_column($rows, 3));
        self::assertContains('room', array_column($rows, 4));

        $text = $this->raw('/audit-log/export', 'root')[1];
        foreach (['AVANT-123', 'APRES-456', '203.0.113.7', 'Appareil-Secret', 'subject_person_id'] as $secret) {
            self::assertStringNotContainsString($secret, $text, $secret . ' ne doit pas sortir.');
        }
        self::assertSame('1', $rows[1][0]);
    }

    public function testFiltresDuJournal(): void
    {
        self::assertCount(3, $this->csv('/audit-log/export?filter[object_type]=data_subject_request', 'root'));
        self::assertCount(2, $this->csv('/audit-log/export?filter[action]=organisation.updated', 'root'));
        self::assertCount(1, $this->csv('/audit-log/export?filter[actor_id]=zorro', 'root'));
        // Le journal horodate avec l'horloge réelle (fuseau Africa/Douala).
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Africa/Douala')))->format('Y-m-d');
        $tomorrow = (new \DateTimeImmutable('tomorrow', new \DateTimeZone('Africa/Douala')))->format('Y-m-d');
        self::assertCount(4, $this->csv('/audit-log/export?filter[from]=' . $today . '&filter[to]=' . $today, 'root'));
        self::assertCount(1, $this->csv('/audit-log/export?filter[from]=' . $tomorrow, 'root'));
        self::assertCount(1, $this->csv('/audit-log/export?filter[to]=2020-01-01', 'root'));
        self::assertSame(422, $this->raw('/audit-log/export?filter[from]=hier', 'root')[0]);
        self::assertSame(422, $this->raw('/audit-log/export?filter[secret]=1', 'root')[0]);
    }

    public function testFormatExcel(): void
    {
        $headers = ['Authorization' => 'Bearer root:t1'];
        $rows = $this->xlsxRows('/audit-log/export?format=xlsx', $headers);
        self::assertSame('Rang', $rows[0][0]);
        self::assertGreaterThanOrEqual(4, count($rows));
        self::assertCount(3, $this->xlsxRows('/data-requests/export?format=xlsx', ['Authorization' => 'Bearer alice:t1']));
        self::assertSame(422, $this->raw('/audit-log/export?format=pdf', 'root')[0]);
    }

    public function testIsolationEntreClients(): void
    {
        $bob = $this->csv('/data-requests/export', 'bob', 't2');
        self::assertCount(2, $bob);
        self::assertSame('p9', $bob[1][2]);

        $journalBob = $this->raw('/audit-log/export', 'bob', 't2')[1];
        self::assertStringNotContainsString('room', $journalBob, 'Le journal de t1 reste chez t1.');
        self::assertStringNotContainsString('p1', $journalBob);

        $journalT1 = $this->raw('/audit-log/export', 'root')[1];
        self::assertStringNotContainsString('p9', $journalT1);
        self::assertStringNotContainsString('p9', $this->raw('/data-requests/export', 'alice')[1]);
    }
}
