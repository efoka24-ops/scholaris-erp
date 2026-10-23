<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use Scholaris\Modules\Socle\Audit\Application\DataSources;
use Scholaris\Modules\Socle\Audit\Console\TenantExportCommand;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Tests\Support\Audit\AuditTestCase;
use Tests\Support\Audit\FakeModuleSource;
use ZipArchive;

final class TenantExportTest extends AuditTestCase
{
    protected function setUp(): void
    {
        $this->bootAudit();
        $this->kernel->container()->get(DataSources::class)->register(new FakeModuleSource());
        $this->newRequest('access');
        $this->newRequest('access', 'p9', 'bob', 't2');
    }

    /** @return array<string, string> */
    private function unzip(string $bytes): array
    {
        $path = tempnam(sys_get_temp_dir(), 'tst');
        file_put_contents((string) $path, $bytes);
        $zip = new ZipArchive();
        self::assertTrue($zip->open((string) $path));
        $files = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $files[(string) $zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
        }
        $zip->close();
        unlink((string) $path);

        return $files;
    }

    public function testExportHttpReserveEtLimiteAuClient(): void
    {
        self::assertSame(403, $this->api('POST', '/privacy/tenant-export', 'alice', 't1')[0]->getStatusCode());

        [$response] = $this->api('POST', '/privacy/tenant-export', 'root', 't1');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/zip', $response->getHeaderLine('Content-Type'));
        $files = $this->unzip((string) $response->getBody());

        self::assertArrayHasKey('manifest.json', $files);
        self::assertArrayHasKey('people/persons.json', $files);
        self::assertArrayHasKey('audit/data_subject_requests.json', $files);
        $requests = json_decode($files['audit/data_subject_requests.json'], true);
        self::assertCount(1, $requests);
        self::assertSame('t1', $requests[0]['tenant_id']);
        self::assertStringNotContainsString('p9', $files['audit/audit_log.json']);
    }

    public function testExportEstJournalise(): void
    {
        $this->api('POST', '/privacy/tenant-export', 'root', 't1');
        $rows = $this->connections()->pdo()->query("SELECT actor_id FROM audit_log WHERE tenant_id = 't1' AND action = 'privacy.tenant_export'")->fetchAll();
        self::assertCount(1, $rows);
        self::assertSame('root', $rows[0]['actor_id']);
        self::assertTrue($this->kernel->container()->get(AuditLog::class)->verify('t1')->intact);
    }

    public function testCommandeConsole(): void
    {
        $file = sys_get_temp_dir() . '/scholaris-export-test-' . bin2hex(random_bytes(4)) . '.zip';
        ob_start();
        $code = $this->kernel->container()->get(TenantExportCommand::class)->run(['t1', $file]);
        $output = (string) ob_get_clean();

        self::assertSame(0, $code);
        self::assertStringContainsString('t1', $output);
        self::assertFileExists($file);
        $files = $this->unzip((string) file_get_contents($file));
        unlink($file);
        self::assertArrayHasKey('manifest.json', $files);
        self::assertSame('t1', json_decode($files['manifest.json'], true)['tenant_id']);
        $row = $this->connections()->pdo()->query("SELECT actor_id FROM audit_log WHERE action = 'privacy.tenant_export'")->fetch();
        self::assertSame('console', $row['actor_id']);
    }
}
