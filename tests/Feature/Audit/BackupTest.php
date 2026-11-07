<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Modules\Socle\Audit\Application\BackupService;
use Scholaris\Modules\Socle\Audit\Console\BackupRestoreCommand;
use Scholaris\Modules\Socle\Audit\Console\BackupRunCommand;
use Tests\Support\AppTestCase;

/** T143 : sauvegarde chiffrée, restauration sur une base neuve, rétention, détection de clé fausse / altération / troncature. */
final class BackupTest extends AppTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/scholaris-backup-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->boot();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    private function service(): BackupService
    {
        return $this->kernel->container()->get(BackupService::class);
    }

    private function pdo(): PDO
    {
        return $this->kernel->container()->get(ConnectionFactory::class)->pdo();
    }

    private function seed(): void
    {
        $now = '2026-10-08T09:00:00+01:00';
        $pdo = $this->pdo();
        $binary = "\xff\xfe\x00binaire\x80";
        $insert = $pdo->prepare('INSERT INTO people (id, tenant_id, first_name, last_name, photo_path, created_at) VALUES (?, ?, ?, ?, ?, ?)');
        $insert->bindValue(1, 'P1');
        $insert->bindValue(2, 't1');
        $insert->bindValue(3, "Éléonore N'Dour 😀");
        $insert->bindValue(4, 'Test');
        $insert->bindValue(5, $binary, PDO::PARAM_LOB);
        $insert->bindValue(6, $now);
        $insert->execute();
        for ($i = 0; $i < 1200; $i++) {
            $pdo->prepare('INSERT INTO person_contacts (id, tenant_id, person_id, kind, value, created_at) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([sprintf('C%05d', $i), 't1', 'P1', 'phone', '+2376' . $i, $now]);
        }
        $pdo->prepare('INSERT INTO idempotency_keys (tenant_id, idem_key, route, request_hash, status_code, response_body, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute(['t1', 'k', '/x', str_repeat('a', 64), 200, '{}', $now]);
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function dump(PDO $pdo): array
    {
        $out = [];
        foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $table) {
            if (in_array($table, BackupService::EXCLUDED, true)) {
                continue;
            }
            $rows = $pdo->query('SELECT * FROM ' . $table)->fetchAll();
            usort($rows, static fn (array $a, array $b): int => strcmp(serialize($a), serialize($b)));
            $out[$table] = $rows;
        }

        return $out;
    }

    public function testSauvegardeChiffreePuRestaurationSurUneBaseNeuve(): void
    {
        $this->seed();
        $key = BackupService::parseKey(BackupService::generateKey());
        $source = $this->service();
        $file = $this->dir . '/test.sbk';
        $result = $source->backup($file, $key);
        $expected = $this->dump($this->pdo());

        self::assertSame(1200, $result['tables']['person_contacts']);
        self::assertArrayNotHasKey('idempotency_keys', $result['tables']);
        self::assertArrayNotHasKey('schema_migrations', $result['tables']);
        self::assertFileExists($file);
        $raw = (string) file_get_contents($file);
        self::assertStringStartsWith(BackupService::MAGIC, $raw);
        self::assertStringNotContainsString('Nadia', $raw);
        self::assertStringNotContainsString('person_contacts', $raw, 'Le contenu est chiffré : aucun nom de table lisible.');
        self::assertStringNotContainsString('N\'Dour', $raw);
        self::assertFileDoesNotExist($file . '.partial');

        // Base neuve, migrée : la restauration refuse sans --force (les lignes de référence des migrations existent déjà).
        $this->boot();
        try {
            $this->service()->restore($file, $key);
            self::fail('Restauration sur tables non vides acceptée.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('--force', $e->getMessage());
        }

        $restored = $this->service()->restore($file, $key, true);
        self::assertSame($result['rows'], $restored['rows']);
        self::assertSame($expected, $this->dump($this->pdo()));
        $first = $this->pdo()->query("SELECT first_name, photo_path FROM people WHERE id = 'P1'")->fetch();
        self::assertSame("Éléonore N'Dour 😀", $first['first_name']);
        self::assertSame("\xff\xfe\x00binaire\x80", $first['photo_path']);
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM idempotency_keys')->fetchColumn());
    }

    /** @return array<string, array{0: callable(string): string}> */
    public static function corruptions(): array
    {
        return [
            'octet modifié au milieu' => [static function (string $raw): string {
                $raw[(int) (strlen($raw) / 2)] = chr(ord($raw[(int) (strlen($raw) / 2)]) ^ 0x01);

                return $raw;
            }],
            'fichier tronqué' => [static fn (string $raw): string => substr($raw, 0, (int) (strlen($raw) * 0.6))],
            'dernier bloc retiré' => [static fn (string $raw): string => substr($raw, 0, -40)],
            'pas une sauvegarde' => [static fn (string $raw): string => 'PK' . $raw],
        ];
    }

    /** @param callable(string): string $corrupt */
    #[DataProvider('corruptions')]
    public function testUneSauvegardeAlthereeEstRefuseeSansRienModifier(callable $corrupt): void
    {
        $this->seed();
        $key = BackupService::parseKey(BackupService::generateKey());
        $file = $this->dir . '/test.sbk';
        $this->service()->backup($file, $key);
        file_put_contents($file, $corrupt((string) file_get_contents($file)));

        $this->boot();
        $before = $this->dump($this->pdo());
        try {
            $this->service()->restore($file, $key, true);
            self::fail('Sauvegarde altérée acceptée.');
        } catch (RuntimeException | \JsonException $e) {
            self::assertNotSame('', $e->getMessage());
        }
        self::assertSame($before, $this->dump($this->pdo()), 'La restauration est atomique : rien n\'est modifié en cas d\'échec.');
    }

    public function testCleIncorrecteEstRefusee(): void
    {
        $this->seed();
        $file = $this->dir . '/test.sbk';
        $this->service()->backup($file, BackupService::parseKey(BackupService::generateKey()));

        $this->boot();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('clé incorrecte');
        $this->service()->restore($file, BackupService::parseKey(BackupService::generateKey()), true);
    }

    public function testCleInvalide(): void
    {
        $this->expectException(RuntimeException::class);
        BackupService::parseKey('trop-court');
    }

    public function testRetentionConserveLesPlusRecentes(): void
    {
        foreach (['20261001-020000', '20261002-020000', '20261003-020000', '20261004-020000'] as $stamp) {
            file_put_contents($this->dir . '/scholaris-' . $stamp . '.sbk', 'x');
        }
        file_put_contents($this->dir . '/autre-fichier.txt', 'x');

        $deleted = $this->service()->prune($this->dir, 2);

        self::assertSame(['scholaris-20261002-020000.sbk', 'scholaris-20261001-020000.sbk'], $deleted);
        self::assertFileExists($this->dir . '/scholaris-20261004-020000.sbk');
        self::assertFileExists($this->dir . '/scholaris-20261003-020000.sbk');
        self::assertFileExists($this->dir . '/autre-fichier.txt');
    }

    public function testCommandesConsole(): void
    {
        $this->seed();
        $key = BackupService::generateKey();
        $config = \Scholaris\Core\Config\Config::fromArray(['backup' => ['key' => $key, 'path' => $this->dir, 'keep' => 2]]);
        $commands = new \Scholaris\Modules\Socle\Audit\Console\BackupCommands($this->service(), $config, dirname(__DIR__, 3));

        ob_start();
        $code = (new BackupRunCommand($commands))->run([]);
        $out = (string) ob_get_clean();

        self::assertSame(0, $code, $out);
        self::assertStringContainsString('Sauvegarde écrite', $out);
        self::assertStringNotContainsString($key, $out);
        $files = glob($this->dir . '/scholaris-*.sbk') ?: [];
        self::assertCount(1, $files);

        ob_start();
        $code = (new BackupRestoreCommand($commands))->run([]);
        ob_end_clean();
        self::assertSame(2, $code);

        $this->boot();
        $commands = new \Scholaris\Modules\Socle\Audit\Console\BackupCommands($this->service(), $config, dirname(__DIR__, 3));
        ob_start();
        $code = (new BackupRestoreCommand($commands))->run([$files[0], '--force']);
        $out = (string) ob_get_clean();
        self::assertSame(0, $code, $out);
        self::assertStringContainsString('Restauration terminée', $out);
        self::assertSame(1200, (int) $this->pdo()->query('SELECT COUNT(*) FROM person_contacts')->fetchColumn());
    }
}
