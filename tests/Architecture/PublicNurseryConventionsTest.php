<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Scholaris\Core\Http\RouteRegistry;
use Scholaris\Modules\Apps\PublicNursery\PublicNurseryApplication;
use Scholaris\Modules\Apps\PublicNursery\Support\GatedAction;

/** Conventions de la Maternelle publique : arborescence courte, contrats du socle seulement et migrations pn_ portables. */
final class PublicNurseryConventionsTest extends TestCase
{
    private const ROOT = '/src/Modules/Apps/PublicNursery';

    private static function root(): string
    {
        return dirname(__DIR__, 2) . self::ROOT;
    }

    /** @return list<string> */
    private static function files(): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::root(), RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = str_replace(DIRECTORY_SEPARATOR, '/', $file->getPathname());
            }
        }
        sort($files);

        return $files;
    }

    /** @return array<string, string> */
    private static function migrations(): array
    {
        $sql = [];
        foreach (glob(self::root() . '/*/Database/Migrations/*.sql') ?: [] as $path) {
            $sql[str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen(dirname(__DIR__, 2)) + 1))] = (string) preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($path));
        }

        return $sql;
    }

    public function testLarborescenceExposeLeManifesteEtLesServicesAttendus(): void
    {
        foreach (['PublicNurseryApplication.php', 'definitions.php', 'routes.php', 'Support'] as $item) {
            self::assertFileExists(self::root() . '/' . $item);
        }
        self::assertSame('platform.applications.public_nursery', PublicNurseryApplication::CONTAINER_KEY);
    }

    public function testLeCodeDuModuleResteEnContractsEtSousModuleLocal(): void
    {
        $offenders = [];
        foreach (self::files() as $file) {
            $source = (string) file_get_contents($file);
            if (!str_contains($source, 'declare(strict_types=1);')) {
                $offenders[] = basename($file) . ' : sans declare(strict_types=1)';
            }
            if (preg_match('/Scholaris\\\\Modules\\\\Socle\\\\\w+\\\\(?!Contracts\\\\)/', $source) === 1) {
                $offenders[] = basename($file) . ' : use du socle hors Contracts';
            }
            foreach (['Illuminate\\', 'Laravel\\', 'use App\\', 'Eloquent'] as $needle) {
                if (str_contains($source, $needle)) {
                    $offenders[] = basename($file) . ' : ' . $needle;
                }
            }
        }

        self::assertSame([], $offenders);
    }

    public function testToutesLesRoutesPassentParLaPorteDuDroitDUsage(): void
    {
        $registry = new RouteRegistry('/api/v1');
        (require self::root() . '/routes.php')($registry);
        $problems = [];
        $writes = 0;
        foreach ($registry->all() as $route) {
            $name = $route['method'] . ' ' . $route['path'];
            if (!str_starts_with($route['path'], '/api/v1/public-nursery/')) {
                $problems[] = $name . ' : préfixe /public-nursery attendu';
            }
            if ($route['handler'][0] !== GatedAction::class) {
                $problems[] = $name . ' : enregistrée hors NurseryRoutes (sans NurseryWriteGate)';
                continue;
            }
            [$mode, $class, $method] = GatedAction::decode($route['handler'][1]);
            if ($mode !== ($route['method'] === 'GET' ? 'read' : 'write')) {
                $problems[] = $name . ' : mode ' . $mode . ' incohérent avec la méthode HTTP (toute route non GET doit être en écriture)';
            }
            if ($route['method'] !== 'GET') {
                ++$writes;
            }
            if (!method_exists($class, $method)) {
                $problems[] = $name . ' : ' . $class . '::' . $method . ' introuvable';
            }
            if (($route['options']['auth'] ?? true) !== true) {
                $problems[] = $name . ' : route publique interdite';
            }
        }

        self::assertNotSame([], $registry->all());
        self::assertGreaterThan(10, $writes, 'Les routes d\'écriture doivent être chargées.');
        self::assertSame([], $problems);
    }

    public function testLesSousModulesNEnregistrentPasDeRouteHorsNurseryRoutes(): void
    {
        $offenders = [];
        foreach (glob(self::root() . '/*/routes.php') ?: [] as $file) {
            $source = (string) file_get_contents($file);
            if (!str_contains($source, 'NurseryRoutes $routes') || preg_match('/->add\(|RouteRegistry/', $source) === 1) {
                $offenders[] = basename(dirname($file)) . '/routes.php';
            }
        }

        self::assertSame([], $offenders, 'Chaque routes.php de sous-module reçoit un NurseryRoutes (WriteGate automatique).');
    }

    public function testLesMigrationsPublicNurseryRespectentLePreFixeEtLeSchemaClient(): void
    {
        $problems = [];
        $establishmentScoped = ['pn_observations', 'pn_progress_books', 'pn_lifebook_entries', 'pn_lifebook_media', 'pn_need_reports'];
        foreach (self::migrations() as $file => $sql) {
            if (!preg_match('#^src/Modules/Apps/PublicNursery/.+/Database/Migrations/\d{12}_public_nursery_[a-z0-9_]+\.sql$#', $file)) {
                $problems[] = $file . ' : nom attendu AAAAMMJJHHMM_public_nursery_<module>.sql';
            }
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
                if (preg_match('/^CREATE TABLE(?: IF NOT EXISTS)?\s+`?(\w+)`?\s*\((.*)\)\s*$/is', $statement, $m) === 1) {
                    if (!str_starts_with($m[1], 'pn_')) {
                        $problems[] = $file . ' : table ' . $m[1] . ' sans préfixe pn_';
                    }
                    if (preg_match('/^\s*tenant_id\s+VARCHAR\(40\)\s+NOT NULL/im', $m[2]) !== 1) {
                        $problems[] = $file . ' : ' . $m[1] . '.tenant_id VARCHAR(40) NOT NULL obligatoire';
                    }
                    if (in_array($m[1], $establishmentScoped, true)
                        && preg_match('/^\s*establishment_id\s+VARCHAR\(40\)\s+NOT NULL/im', $m[2]) !== 1) {
                        $problems[] = $file . ' : ' . $m[1] . '.establishment_id VARCHAR(40) NOT NULL obligatoire';
                    }
                }
            }
        }

        self::assertSame([], $problems);
    }

    public function testLesMigrationsNeSontPasDateesDansLeFuturEtGardentLOrdreDesModules(): void
    {
        $stamps = [];
        foreach (array_keys(self::migrations()) as $file) {
            $stamps[basename($file)] = (int) substr(basename($file), 0, 12);
        }

        $now = (int) (new \DateTimeImmutable('now', new \DateTimeZone('Africa/Douala')))->format('YmdHi');
        foreach ($stamps as $name => $stamp) {
            self::assertLessThanOrEqual($now, $stamp, $name . ' est datée dans le futur.');
        }
        $order = array_keys($stamps);
        sort($order);
        $modules = array_map(static fn (string $name): string => (string) preg_replace('/^\d{12}_|\.sql$/', '', $name), $order);
        self::assertSame(
            ['public_nursery_pickup', 'public_nursery_development', 'public_nursery_transition'],
            array_slice($modules, 0, 3),
            'Ordre Pickup puis Development puis Transition ; les migrations de finalisation (N-002) viennent après.',
        );
        foreach (array_slice($order, 3) as $name) {
            self::assertGreaterThan(202610231300, $stamps[$name], $name . ' : toute nouvelle migration est postérieure au 2026-10-23 13:00.');
        }
    }

    public function testAucunMotReserveMysqlDansLesMigrationsPublicNursery(): void
    {
        $reserved = array_flip(explode(' ', (string) (new \ReflectionClassConstant(\Tests\Architecture\MysqlCompatibilityTest::class, 'RESERVED'))->getValue()));
        $hits = [];
        foreach (self::migrations() as $file => $sql) {
            preg_match_all('/CREATE TABLE(?: IF NOT EXISTS)?\s+`?(\w+)`?/i', $sql, $tables);
            preg_match_all('/^\s+`?(\w+)`?\s+(?:CHAR|VARCHAR|TEXT|LONGTEXT|MEDIUMTEXT|INTEGER|INT|BIGINT|SMALLINT|TINYINT|DECIMAL|BLOB|DATETIME|DATE|TIMESTAMP|FLOAT|DOUBLE|JSON|BOOLEAN)\b/im', $sql, $columns);
            foreach (array_merge($tables[1], $columns[1]) as $name) {
                if (isset($reserved[strtolower($name)])) {
                    $hits[] = $file . ' : ' . $name;
                }
            }
            foreach (['AUTO_INCREMENT', 'AUTOINCREMENT', 'ENGINE', 'FOREIGN KEY', 'REFERENCES'] as $word) {
                if (stripos($sql, $word) !== false) {
                    $hits[] = $file . ' : ' . $word;
                }
            }
        }

        self::assertSame([], $hits);
    }
}
