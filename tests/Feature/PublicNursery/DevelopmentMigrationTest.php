<?php

declare(strict_types=1);

namespace Tests\Feature\PublicNursery;

use PHPUnit\Framework\TestCase;
use Scholaris\Core\Config\Config;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Database\Migrator;
use Scholaris\Core\Kernel;

final class DevelopmentMigrationTest extends TestCase
{
    public function testLaMigrationDevCreerLesTablesEtIndexAttendus(): void
    {
        $kernel = Kernel::boot(dirname(__DIR__, 3), Config::fromArray([
            'app' => ['timezone' => 'Africa/Douala', 'log_path' => 'storage/logs/test.log', 'api_prefix' => '/api/v1'],
            'database' => ['driver' => 'sqlite', 'database' => ':memory:'],
        ]));
        $kernel->container()->get(Migrator::class)->migrate();
        $pdo = $kernel->container()->get(ConnectionFactory::class)->pdo();

        foreach (['pn_dev_frames', 'pn_dev_domains', 'pn_dev_items', 'pn_observations', 'pn_progress_books', 'pn_phrase_bank', 'pn_lifebook_entries', 'pn_lifebook_media', 'pn_lifebook_media_children', 'pn_need_reports'] as $table) {
            $exists = (string) $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = '" . $table . "'")->fetchColumn();
            self::assertSame($table, $exists, $table . ' doit exister');
        }
        foreach (['pn_dev_frames_version', 'pn_dev_domains_code', 'pn_dev_items_code', 'pn_observations_client', 'pn_observations_learner', 'pn_progress_books_period', 'pn_lifebook_entries_section', 'pn_lifebook_media_entry', 'pn_lifebook_media_purge', 'pn_lifebook_media_child', 'pn_lifebook_child_learner', 'pn_need_reports_learner'] as $index) {
            $exists = (string) $pdo->query("SELECT name FROM sqlite_master WHERE type = 'index' AND name = '" . $index . "'")->fetchColumn();
            self::assertSame($index, $exists, $index . ' doit exister');
        }
    }
}
