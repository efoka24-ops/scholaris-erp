<?php

declare(strict_types=1);

namespace Tests\Feature\PublicNursery;

use PHPUnit\Framework\TestCase;
use Scholaris\Core\Config\Config;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Database\Migrator;
use Scholaris\Core\Kernel;

final class TransitionMigrationTest extends TestCase
{
    public function testLaMigrationTraCreerLesTablesEtIndexAttendus(): void
    {
        $kernel = Kernel::boot(dirname(__DIR__, 3), Config::fromArray([
            'app' => ['timezone' => 'Africa/Douala', 'log_path' => 'storage/logs/test.log', 'api_prefix' => '/api/v1'],
            'database' => ['driver' => 'sqlite', 'database' => ':memory:'],
        ]));
        $kernel->container()->get(Migrator::class)->migrate();
        $pdo = $kernel->container()->get(ConnectionFactory::class)->pdo();

        foreach (['pn_transition_decisions', 'pn_liaison_sheets'] as $table) {
            $exists = (string) $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = '" . $table . "'")->fetchColumn();
            self::assertSame($table, $exists, $table . ' doit exister');
        }
        foreach (['pn_transition_year', 'pn_liaison_year'] as $index) {
            $exists = (string) $pdo->query("SELECT name FROM sqlite_master WHERE type = 'index' AND name = '" . $index . "'")->fetchColumn();
            self::assertSame($index, $exists, $index . ' doit exister');
        }
    }
}
