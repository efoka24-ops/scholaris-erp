<?php

declare(strict_types=1);

namespace Tests\Feature;

use Scholaris\Core\Database\Migrator;
use Tests\Support\AppTestCase;

final class MigratorTest extends AppTestCase
{
    public function testLesMigrationsDuNoyauEtDesModulesSontAppliqueesUneSeuleFois(): void
    {
        $this->boot();
        $migrator = $this->kernel->container()->get(Migrator::class);

        $status = $migrator->status();
        self::assertArrayHasKey('202610081000_core_tables.sql', $status);
        self::assertArrayHasKey('202610081010_audit_tables.sql', $status);
        self::assertNotContains(false, $status, 'Tout est déjà appliqué après boot().');
        self::assertSame([], $migrator->migrate(), 'Un second passage ne fait rien.');

        $tables = $this->connections()->pdo()->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(\PDO::FETCH_COLUMN);
        foreach (['idempotency_keys', 'rate_limits', 'number_sequences', 'audit_log', 'approvals', 'schema_migrations'] as $table) {
            self::assertContains($table, $tables);
        }
    }
}
