<?php

declare(strict_types=1);

namespace Tests\Feature\PublicNursery;

use PHPUnit\Framework\TestCase;
use Scholaris\Core\Config\Config;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Database\Migrator;
use Scholaris\Core\Kernel;

final class PickupMigrationTest extends TestCase
{
    public function testLaMigrationSorCreerLesTablesEtIndexAttendus(): void
    {
        $kernel = Kernel::boot(dirname(__DIR__, 3), Config::fromArray([
            'app' => ['timezone' => 'Africa/Douala', 'log_path' => 'storage/logs/test.log', 'api_prefix' => '/api/v1'],
            'database' => ['driver' => 'sqlite', 'database' => ':memory:'],
        ]));
        $kernel->container()->get(Migrator::class)->migrate();
        $pdo = $kernel->container()->get(ConnectionFactory::class)->pdo();

        foreach (['pn_gate_events', 'pn_late_alerts', 'pn_custody_restrictions', 'pn_pickup_codes', 'pn_pickup_cards', 'pn_sync_batches'] as $table) {
            $exists = (string) $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = '" . $table . "'")->fetchColumn();
            self::assertSame($table, $exists, $table . ' doit exister');
        }
        foreach (['pn_gate_events_client', 'pn_late_alerts_once', 'pn_custody_learner', 'pn_pickup_codes_day', 'pn_pickup_cards_pickup', 'pn_sync_batches_uuid'] as $index) {
            $exists = (string) $pdo->query("SELECT name FROM sqlite_master WHERE type = 'index' AND name = '" . $index . "'")->fetchColumn();
            self::assertSame($index, $exists, $index . ' doit exister');
        }
    }
}
