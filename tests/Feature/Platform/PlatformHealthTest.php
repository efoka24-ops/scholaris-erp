<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use DI\Container;
use Scholaris\Modules\Socle\Integrations\Contracts\GatewayStatus;
use Tests\Support\Platform\PlatformTestCase;

/** C-112 point 1 : santé de la plateforme (sondes, seuils réglables, cache TeeeOTP de 10 minutes). */
final class PlatformHealthTest extends PlatformTestCase
{
    private string $root;
    private int $now;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/scholaris-health-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/storage/logs', 0777, true);
        mkdir($this->root . '/storage/backups', 0777, true);
        mkdir($this->root . '/storage/uploads', 0777, true);
        $this->bootPlatform(['platform' => ['health_root' => $this->root], 'app' => ['log_path' => 'storage/logs/app.log'], 'backup' => ['path' => 'storage/backups']]);
        $this->now = $this->clock->now();
        $this->permissions->grant(self::ROOT, 'platform', 'platform.health.read');
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    /** @return array<string, array<string, mixed>> sondes par clé */
    private function probes(): array
    {
        [$response, $json] = $this->api('GET', '/platform/health', self::ROOT);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return array_column($json['data']['probes'], null, 'key');
    }

    private function at(int $secondsAgo): string
    {
        return date('Y-m-d H:i', $this->now - $secondsAgo);
    }

    public function testSondesValeursEtSeuils(): void
    {
        file_put_contents($this->root . '/storage/schedule.last', date(DATE_ATOM, $this->now - 300));
        file_put_contents($this->root . '/storage/logs/schedule.log', implode("\n", [
            '[' . $this->at(3600) . '] sms:sync-status : code 0 en 0.1 s',
            '[' . $this->at(1800) . '] backup:run : code 1 en 2.0 s | Disque plein',
            '[' . $this->at(200000) . '] queue:send : code 1 en 0.1 s',
        ]) . "\n");
        $iso = static fn (int $t): string => date('Y-m-d\TH:i:s.uP', $t);
        file_put_contents($this->root . '/storage/logs/app.log', implode("\n", [
            '[' . $iso($this->now - 600) . '] scholaris.ERROR: Erreur non gérée {"trace_id":"abc123","exception":"[object] (RuntimeException)"} []',
            '[' . $iso($this->now - 500) . '] scholaris.WARNING: Requête refusée {"trace_id":"zzz"} []',
            '[' . $iso($this->now - 90000) . '] scholaris.ERROR: Erreur non gérée {"trace_id":"old"} []',
        ]) . "\n");
        $backup = $this->root . '/storage/backups/scholaris-2026-10-08.sql.gz.enc';
        file_put_contents($backup, 'x');
        touch($backup, $this->now - 30 * 3600);

        $probes = $this->probes();
        self::assertSame('ok', $probes['database']['status']);
        self::assertSame('ok', $probes['cron_last_run']['status']);
        self::assertSame(5, $probes['cron_last_run']['value']);
        self::assertSame(['warning' => 20, 'critical' => 60, 'direction' => 'above'], $probes['cron_last_run']['thresholds']);
        self::assertSame('warning', $probes['cron_failures']['status']);
        self::assertSame(1, $probes['cron_failures']['value'], 'seulement les échecs des dernières 24 h');
        self::assertStringContainsString('backup:run', (string) $probes['cron_failures']['detail']);
        self::assertSame('ok', $probes['message_queue']['status']);
        self::assertSame('unknown', $probes['document_batches']['status']);
        self::assertSame('warning', $probes['sms_gateway']['status'], 'pilote journal : aucun SMS ne part');
        self::assertSame('warning', $probes['smtp']['status']);
        self::assertSame('warning', $probes['backup']['status']);
        self::assertSame(30, $probes['backup']['value']);
        self::assertSame(1, $probes['errors_24h']['value']);
        self::assertSame(['abc123'], $probes['errors_24h']['trace_ids']);
        self::assertContains($probes['disk']['status'], ['ok', 'warning', 'critical']);

        // Seuil réglable : une seule erreur devient critique.
        $this->api('PATCH', '/platform/settings', self::ROOT, null, ['health_errors_warning' => 1, 'health_errors_critical' => 1]);
        self::assertSame('critical', $this->probes()['errors_24h']['status']);

        [, $dashboard] = $this->api('GET', '/platform/dashboard', self::ROOT);
        self::assertTrue($dashboard['data']['health']['available']);
        self::assertSame('critical', $dashboard['data']['health']['status']);
    }

    public function testPlanificateurArreteEtSauvegardeAbsenteSontCritiques(): void
    {
        file_put_contents($this->root . '/storage/schedule.last', date(DATE_ATOM, $this->now - 2 * 3600));
        $probes = $this->probes();
        self::assertSame('critical', $probes['cron_last_run']['status']);
        self::assertSame('unknown', $probes['cron_failures']['status']);
        self::assertSame('critical', $probes['backup']['status']);
        self::assertSame('ok', $probes['errors_24h']['status']);
    }

    public function testQuotaTeeeOtpMisEnCacheDixMinutes(): void
    {
        $fake = new class () implements GatewayStatus {
            public int $calls = 0;
            public int $quota = 40;

            public function sms(): array
            {
                $this->calls++;

                return ['driver' => 'teeeotp', 'configured' => true, 'reachable' => true, 'mode' => 'live', 'quota_remaining' => $this->quota, 'error' => null];
            }

            public function mail(): array
            {
                return ['driver' => 'smtp', 'configured' => true];
            }

            public function emailPreview(string $subject, string $body): string
            {
                return '<html>' . $subject . '</html>';
            }
        };
        $container = $this->kernel->container();
        self::assertInstanceOf(Container::class, $container);
        $container->set(GatewayStatus::class, $fake);

        $probes = $this->probes();
        self::assertSame('critical', $probes['sms_gateway']['status'], '40 SMS restants : sous le seuil critique (50)');
        self::assertSame(40, $probes['sms_gateway']['value']);
        self::assertSame('live', $probes['sms_gateway']['mode']);
        self::assertSame('ok', $probes['smtp']['status']);
        $fake->quota = 5000;
        self::assertSame(40, $this->probes()['sms_gateway']['value'], 'cache de 10 minutes');
        self::assertSame(1, $fake->calls);
        $this->clock->set(date(DATE_ATOM, $this->now + 601));
        self::assertSame('ok', $this->probes()['sms_gateway']['status']);
        self::assertSame(2, $fake->calls);
    }

    public function testReserveeALaPermissionDeSante(): void
    {
        $this->permissions->grant('finance', 'platform', 'platform.access', 'platform.dashboard.read', 'platform.kpi.finance');
        self::assertSame(403, $this->api('GET', '/platform/health', 'finance')[0]->getStatusCode());
        [, $dashboard] = $this->api('GET', '/platform/dashboard', 'finance');
        self::assertFalse($dashboard['data']['health']['available']);
    }
}
