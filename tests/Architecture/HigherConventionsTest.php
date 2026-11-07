<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Scholaris\Core\Http\RouteRegistry;
use Scholaris\Modules\Apps\Higher\HigherApplication;
use Scholaris\Modules\Apps\Higher\Support\GatedAction;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationManifest;

/**
 * Conventions de l'application Supérieur (wave-plan 0.2), vérifiées sur tout src/Modules/Apps/Higher, sous-modules compris :
 * seuls les Contracts du socle, aucun Laravel, tables hi_* avec tenant_id et establishment_id, aucun mot réservé MySQL 8,
 * aucune lecture ni écriture de table d'un autre module, toute route enregistrée par HigherRoutes (préfixe /higher, WriteGate
 * avant chaque écriture, lecture contrôlée), un seul routes.php et un seul definitions.php chargés par le noyau.
 */
final class HigherConventionsTest extends TestCase
{
    private const ROOT = '/src/Modules/Apps/Higher';

    private static function root(): string
    {
        return dirname(__DIR__, 2) . self::ROOT;
    }

    /** @return list<string> */
    private static function files(string $extension): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::root(), RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === $extension) {
                $files[] = str_replace(DIRECTORY_SEPARATOR, '/', $file->getPathname());
            }
        }
        sort($files);

        return $files;
    }

    private static function relative(string $path): string
    {
        return substr($path, strlen(str_replace(DIRECTORY_SEPARATOR, '/', self::root())) + 1);
    }

    /** @return array<string, string> fichier => SQL sans commentaires (racine et sous-modules) */
    private static function migrations(): array
    {
        $sql = [];
        foreach (array_merge(glob(self::root() . '/Database/Migrations/*.sql') ?: [], glob(self::root() . '/*/Database/Migrations/*.sql') ?: []) as $path) {
            $sql[self::relative(str_replace(DIRECTORY_SEPARATOR, '/', $path))] = (string) preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($path));
        }

        return $sql;
    }

    public function testLArborescenceDesSousModulesEstEnPlace(): void
    {
        foreach ([...HigherApplication::MODULES, 'Policies', 'Support'] as $module) {
            self::assertDirectoryExists(self::root() . '/' . $module, $module);
        }
        foreach (HigherApplication::MODULES as $module) {
            foreach (['Application', 'Domain', 'Infrastructure', 'Http', 'Database/Migrations'] as $folder) {
                self::assertDirectoryExists(self::root() . '/' . $module . '/' . $folder, $module . '/' . $folder);
            }
        }
    }

    public function testAucunUseDuSocleHorsContractsNiDeLaravel(): void
    {
        $offenders = [];
        foreach (self::files('php') as $file) {
            $source = (string) file_get_contents($file);
            if (preg_match('/Scholaris\\\\Modules\\\\Socle\\\\\w+\\\\(?!Contracts\\\\)/', $source, $m) === 1
                || preg_match('/\b(?:Illuminate|Laravel)\\\\|\buse\s+App\\\\|\bArtisan::|\bEloquent\b/', $source, $m) === 1) {
                $offenders[] = self::relative($file) . ' : ' . $m[0];
            }
            if (!str_contains($source, 'declare(strict_types=1);')) {
                $offenders[] = self::relative($file) . ' : sans declare(strict_types=1)';
            }
        }

        self::assertSame([], $offenders);
    }

    public function testLesTablesHigherSontPrefixeesEtPortentClientEtEtablissement(): void
    {
        $problems = [];
        foreach (self::migrations() as $file => $sql) {
            if (preg_match('#^(?:\w+/)?Database/Migrations/\d{12}_higher_[a-z0-9_]+\.sql$#', $file) !== 1) {
                $problems[] = $file . ' : nom attendu AAAAMMJJHHMM_higher_<module>_<objet>.sql';
            }
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
                if (preg_match('/^CREATE TABLE(?: IF NOT EXISTS)?\s+`?(\w+)`?\s*\((.*)\)\s*$/is', $statement, $m) === 1) {
                    if (!str_starts_with($m[1], HigherApplication::TABLE_PREFIX)) {
                        $problems[] = $file . ' : table ' . $m[1] . ' sans préfixe hi_';
                    }
                    foreach (['tenant_id', 'establishment_id'] as $column) {
                        if (preg_match('/^\s*' . $column . '\s+VARCHAR\(40\)\s+NOT NULL/im', $m[2]) !== 1) {
                            $problems[] = $file . ' : ' . $m[1] . '.' . $column . ' VARCHAR(40) NOT NULL obligatoire';
                        }
                    }
                }
                if (preg_match('/^(?:ALTER TABLE|INSERT INTO|UPDATE|DELETE FROM|DROP TABLE(?: IF EXISTS)?|CREATE (?:UNIQUE )?INDEX \w+ ON)\s+`?(\w+)`?/i', $statement, $m) === 1
                    && !str_starts_with($m[1], HigherApplication::TABLE_PREFIX)) {
                    $problems[] = $file . ' : modifie la table ' . $m[1] . ' d\'un autre module';
                }
            }
        }

        self::assertSame([], $problems);
    }

    public function testAucunMotReserveMysqlDansLesMigrationsHigher(): void
    {
        $reserved = array_flip(explode(' ', (string) (new \ReflectionClassConstant(MysqlCompatibilityTest::class, 'RESERVED'))->getValue()));
        $types = 'CHAR|VARCHAR|TEXT|LONGTEXT|MEDIUMTEXT|INTEGER|INT|BIGINT|SMALLINT|TINYINT|DECIMAL|BLOB|DATETIME|DATE|TIMESTAMP|FLOAT|DOUBLE|JSON|BOOLEAN';
        $hits = [];
        foreach (self::migrations() as $file => $sql) {
            preg_match_all('/CREATE TABLE(?: IF NOT EXISTS)?\s+`?(\w+)`?/i', $sql, $tables);
            preg_match_all('/^\s+`?(\w+)`?\s+(?:' . $types . ')\b/im', $sql, $columns);
            preg_match_all('/ADD COLUMN\s+`?(\w+)`?/i', $sql, $added);
            foreach (array_merge($tables[1], $columns[1], $added[1]) as $name) {
                if (isset($reserved[strtolower($name)])) {
                    $hits[] = $file . ' : ' . $name;
                }
            }
            foreach (['AUTO_INCREMENT', 'AUTOINCREMENT', 'ENGINE', 'FOREIGN KEY', 'REFERENCES'] as $word) {
                if (stripos($sql, $word) !== false) {
                    $hits[] = $file . ' : ' . $word . ' (non portable ou clé étrangère interdite)';
                }
            }
        }

        self::assertSame([], $hits);
    }

    public function testLeCodeHigherNeLitNiNEcritAucuneTableDUnAutreModule(): void
    {
        $offenders = [];
        foreach (self::files('php') as $file) {
            foreach (token_get_all((string) file_get_contents($file)) as $token) {
                if (!is_array($token) || !in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                    continue;
                }
                if (preg_match_all('/\b(?:FROM|JOIN|INTO|UPDATE)\s+`?([a-z][a-z0-9_]*)`?/', $token[1], $m) > 0) {
                    foreach ($m[1] as $table) {
                        if (!str_starts_with($table, HigherApplication::TABLE_PREFIX)) {
                            $offenders[] = self::relative($file) . ' : ' . $table;
                        }
                    }
                }
            }
        }

        self::assertSame([], $offenders, 'Higher ne lit que ses tables hi_* ; les autres données passent par les Contracts du socle.');
    }

    public function testToutesLesRoutesHigherPassentParLaPorte(): void
    {
        $registry = new RouteRegistry('/api/v1');
        (require self::root() . '/routes.php')($registry);
        $problems = [];
        foreach ($registry->all() as $route) {
            $name = $route['method'] . ' ' . $route['path'];
            if (!str_starts_with($route['path'], '/api/v1/higher/')) {
                $problems[] = $name . ' : préfixe /higher attendu';
            }
            if ($route['handler'][0] !== GatedAction::class) {
                $problems[] = $name . ' : enregistrée hors HigherRoutes (sans WriteGate)';
                continue;
            }
            [$mode, $class, $method] = GatedAction::decode($route['handler'][1]);
            if ($mode !== ($route['method'] === 'GET' ? 'read' : 'write')) {
                $problems[] = $name . ' : mode ' . $mode . ' incohérent avec la méthode';
            }
            if (!method_exists($class, $method)) {
                $problems[] = $name . ' : ' . $class . '::' . $method . ' introuvable';
            }
            if (($route['options']['auth'] ?? true) !== true) {
                $problems[] = $name . ' : route publique interdite';
            }
        }

        self::assertNotSame([], $registry->all());
        self::assertSame([], $problems);
    }

    public function testLesRoutesDesSousModulesNePassentQueParHigherRoutes(): void
    {
        $offenders = [];
        foreach (glob(self::root() . '/*/routes.php') ?: [] as $file) {
            $source = (string) file_get_contents($file);
            if (preg_match('/RouteRegistry|->add\s*\(|->addRoute\s*\(/', $source) === 1 || !str_contains($source, 'HigherRoutes')) {
                $offenders[] = self::relative(str_replace(DIRECTORY_SEPARATOR, '/', $file));
            }
        }
        foreach (self::files('php') as $file) {
            $relative = self::relative($file);
            if (preg_match('#^[^/]+/.+/(routes|definitions)\.php$#', $relative) === 1) {
                $offenders[] = $relative . ' : un seul routes.php / definitions.php par sous-module, à sa racine';
            }
        }

        self::assertSame([], $offenders);
    }

    public function testLeManifesteEstExposeAuCatalogueDeLaPlateforme(): void
    {
        $definitions = require self::root() . '/definitions.php';

        self::assertIsArray($definitions);
        self::assertInstanceOf(ApplicationManifest::class, $definitions['platform.applications.higher'] ?? null);
        self::assertSame('higher', $definitions['platform.applications.higher']->key);
        foreach (array_keys($definitions) as $key) {
            self::assertFalse(is_string($key) && str_starts_with($key, 'platform.applications.') && $key !== 'platform.applications.higher', 'Une seule clé de manifeste : ' . $key);
        }
    }
}
