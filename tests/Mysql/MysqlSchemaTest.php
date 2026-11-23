<?php

declare(strict_types=1);

namespace Tests\Mysql;

use Scholaris\Core\Database\Migrator;
use Scholaris\Modules\Socle\Audit\Console\DbCheckCommand;
use Tests\Support\AppTestCase;

/**
 * Suite MySQL uniquement (phpunit.mysql.xml) : les migrations s'appliquent sur une base MySQL vide, db:check est vert
 * (schéma, utf8mb4, InnoDB, écriture/lecture annulées).
 */
final class MysqlSchemaTest extends AppTestCase
{
    protected function setUp(): void
    {
        $this->boot();
    }

    public function testLeMoteurEstBienMysql(): void
    {
        self::assertSame('mysql', $this->connections()->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME));
        self::assertStringStartsWith('8.', (string) $this->connections()->pdo()->getAttribute(\PDO::ATTR_SERVER_VERSION));
    }

    public function testToutesLesMigrationsSontAppliqueesEtRejouablesSansEffet(): void
    {
        $migrator = $this->kernel->container()->get(Migrator::class);

        self::assertSame([], array_keys(array_filter($migrator->status(), static fn (bool $applied): bool => !$applied)));
        self::assertSame([], $migrator->migrate());
    }

    public function testDbCheckEstVertSurMysql(): void
    {
        $command = $this->kernel->container()->get('console.command.db:check');
        self::assertInstanceOf(DbCheckCommand::class, $command);

        ob_start();
        $code = $command->run();
        $output = (string) ob_get_clean();

        self::assertSame(0, $code, $output);
        self::assertStringContainsString('[OK] Jeu de caractères de la base', $output);
        self::assertStringContainsString('[OK] Tables en utf8mb4', $output);
        self::assertStringContainsString('[OK] Moteur transactionnel InnoDB', $output);
        self::assertStringContainsString('[OK] Écriture, lecture, modification, suppression', $output);
    }

    public function testLesDonneesDeReferenceSontRestaureesEntreLesTests(): void
    {
        $pdo = $this->connections()->pdo();
        self::assertGreaterThan(0, (int) $pdo->query('SELECT COUNT(*) FROM roles')->fetchColumn());
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM people')->fetchColumn());
    }
}
