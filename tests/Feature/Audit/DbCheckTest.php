<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use Scholaris\Modules\Socle\Audit\Console\DbCheckCommand;
use Tests\Support\AppTestCase;

/** db:check sur la base de test (SQLite) : rapport OK, puis écarts détectés (migration manquante, colonne absente, table absente). */
final class DbCheckTest extends AppTestCase
{
    protected function setUp(): void
    {
        $this->boot();
    }

    /** @return array{0: int, 1: string} */
    private function execute(): array
    {
        $command = $this->kernel->container()->get('console.command.db:check');
        self::assertInstanceOf(DbCheckCommand::class, $command);
        ob_start();
        $code = $command->run();

        return [$code, (string) ob_get_clean()];
    }

    public function testBaseConformeDonneUnRapportOkEtNeLaisseAucuneDonnee(): void
    {
        [$code, $out] = $this->execute();

        self::assertSame(0, $code, $out);
        self::assertStringContainsString('[OK] Migrations appliquées', $out);
        self::assertStringContainsString('[OK] Tables et colonnes attendues', $out);
        self::assertStringContainsString('[OK] Écriture, lecture, modification, suppression', $out);
        self::assertStringContainsString('Résultat : OK, aucun écart.', $out);
        $pdo = $this->connections()->pdo();
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM tenants WHERE id LIKE 'dbcheck%'")->fetchColumn());
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM number_sequences WHERE tenant_id LIKE 'dbcheck%'")->fetchColumn());
        self::assertFalse($pdo->inTransaction());
    }

    public function testMigrationEnAttenteEstUnEcart(): void
    {
        $this->connections()->pdo()->exec("DELETE FROM schema_migrations WHERE name LIKE '%core_tables%'");

        [$code, $out] = $this->execute();

        self::assertSame(1, $code);
        self::assertStringContainsString('[ÉCART] Migrations appliquées', $out);
        self::assertStringContainsString('core_tables', $out);
    }

    public function testTableOuColonneAbsenteEstUnEcart(): void
    {
        $pdo = $this->connections()->pdo();
        $pdo->exec('DROP TABLE rooms');
        $pdo->exec('ALTER TABLE periods DROP COLUMN updated_at');

        [$code, $out] = $this->execute();

        self::assertSame(1, $code);
        self::assertStringContainsString('tables absentes : rooms', $out);
        self::assertStringContainsString('periods.updated_at', $out);
    }

    public function testTableDeControleAbsenteEstUnEcartFonctionnel(): void
    {
        $this->connections()->pdo()->exec('DROP TABLE idempotency_keys');

        [$code, $out] = $this->execute();

        self::assertSame(1, $code);
        self::assertStringContainsString('[ÉCART] Écriture, lecture, modification, suppression', $out);
    }
}
