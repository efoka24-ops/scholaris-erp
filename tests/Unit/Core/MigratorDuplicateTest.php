<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Scholaris\Core\Config\Config;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Database\Migrator;

/** Une migration déplacée d'un module à un autre et laissée à son ancien emplacement ne bloque pas le déploiement. */
final class MigratorDuplicateTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/scholaris-migrator-' . bin2hex(random_bytes(4));
        mkdir($this->base . '/src/Modules/Apps/Old/Registry/Database/Migrations', 0o777, true);
        mkdir($this->base . '/src/Modules/Apps/New/Schools/Database/Migrations', 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->base . '/src/Modules/Apps/*/*/Database/Migrations/*.sql') ?: [] as $file) {
            unlink($file);
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->base, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($this->base);
    }

    /** @return array<string, string> */
    private function files(): array
    {
        $migrator = new Migrator(new ConnectionFactory(Config::fromArray(['database' => ['driver' => 'sqlite', 'database' => ':memory:']])), $this->base);

        return (new \ReflectionMethod(Migrator::class, 'files'))->invoke($migrator);
    }

    public function testDeuxCopiesIdentiquesNeSontPasUnConflit(): void
    {
        $sql = 'CREATE TABLE exemple_a (id INTEGER);';
        file_put_contents($this->base . '/src/Modules/Apps/Old/Registry/Database/Migrations/202610151002_exemple.sql', $sql);
        file_put_contents($this->base . '/src/Modules/Apps/New/Schools/Database/Migrations/202610151002_exemple.sql', $sql);

        self::assertCount(1, $this->files());
    }

    public function testDeuxContenusDifferentsRestentUnConflit(): void
    {
        file_put_contents($this->base . '/src/Modules/Apps/Old/Registry/Database/Migrations/202610151002_exemple.sql', 'CREATE TABLE a (id INTEGER);');
        file_put_contents($this->base . '/src/Modules/Apps/New/Schools/Database/Migrations/202610151002_exemple.sql', 'CREATE TABLE b (id INTEGER);');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Nom de migration en double');
        $this->files();
    }
}
