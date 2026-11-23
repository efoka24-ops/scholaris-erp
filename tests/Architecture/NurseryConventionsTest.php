<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Scholaris\Core\Http\RouteRegistry;
use Scholaris\Modules\Apps\Nursery\NurseryApplication;
use Scholaris\Modules\Apps\Nursery\Support\GatedAction;

/**
 * Conventions de la Maternelle privée (spec 007, plan §10) : contrats du socle seulement, aucune dépendance à une autre
 * application, toute route (lectures comprises) gardée par la porte du droit d'usage, tables `nu_*` portables et cloisonnées,
 * aucune colonne de note, aucun seuil codé en dur (SC-MAT-5), migrations jamais datées dans le futur. Les contrôles sont des
 * fonctions pures sur du texte : leurs tests « mauvais exemple » prouvent qu'ils échouent quand la règle est violée.
 */
final class NurseryConventionsTest extends TestCase
{
    /** Première migration autorisée à la Maternelle privée (plus récente que la dernière du socle connue à l'écriture). */
    private const FIRST_MIGRATION = 202610211222;
    private const FORBIDDEN_COLUMNS = ['score', 'rank', 'total', 'average', 'avg', 'grade', 'mark', 'coefficient', 'note', 'points'];
    /** Mots réservés MySQL 8 susceptibles d'être choisis comme noms de colonne (liste volontairement ciblée sur ce qu'on rencontre). */
    private const MYSQL_RESERVED = ['order', 'group', 'key', 'rank', 'level', 'index', 'condition', 'range', 'rows', 'row', 'system', 'read', 'release', 'show', 'desc', 'both', 'leading', 'interval', 'usage', 'lock', 'write', 'schema', 'check', 'column', 'database', 'default', 'delete', 'describe', 'distinct', 'explain', 'force', 'from', 'grant', 'having', 'insert', 'into', 'join', 'kill', 'limit', 'match', 'option', 'primary', 'procedure', 'references', 'rename', 'replace', 'require', 'select', 'set', 'table', 'to', 'trigger', 'unique', 'update', 'use', 'where', 'window', 'function', 'groups', 'lag', 'lead', 'rank', 'cube', 'empty', 'except', 'first_value', 'json_table', 'last_value', 'nth_value', 'ntile', 'of', 'over', 'percent_rank', 'recursive', 'row_number', 'cume_dist', 'dense_rank', 'lateral', 'generated', 'stored', 'virtual'];

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function appRoot(): string
    {
        return self::root() . '/src/Modules/Apps/Nursery';
    }

    /** @return list<string> */
    private static function files(string $extension = 'php'): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::appRoot(), RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === $extension) {
                $files[] = str_replace(DIRECTORY_SEPARATOR, '/', $file->getPathname());
            }
        }
        sort($files);

        return $files;
    }

    /** @return list<string> problèmes : toute référence à une autre application ou à un interne du socle */
    public static function dependencyProblems(string $source): array
    {
        $problems = [];
        if (preg_match('/Scholaris\\\\Modules\\\\Apps\\\\(?!Nursery\b)\w+/', $source, $m) === 1) {
            $problems[] = 'dépend d\'une autre application : ' . $m[0];
        }
        if (preg_match('/Scholaris\\\\Modules\\\\Socle\\\\\w+\\\\(?!Contracts\\\\)/', $source, $m) === 1) {
            $problems[] = 'utilise le socle hors Contracts : ' . $m[0];
        }
        if (preg_match('/\b(?:FROM|JOIN|INTO|UPDATE)\s+(?!nu_)(?:users|people|authorized_pickups|enrollments|invoices|settings|attendance_\w+)\b/i', $source, $m) === 1) {
            $problems[] = 'lit ou écrit la table d\'un autre module : ' . $m[0];
        }

        return $problems;
    }

    /** @return list<string> */
    public static function thresholdProblems(string $source): array
    {
        $code = (string) preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m', '#^\s*\*.*$#m'], '', $source);
        $problems = [];
        $pattern = '/\b(\w*(?:threshold|seuil|delay|delai|minutes|attempts?|retention|max_?age|late_?after|grace|expire)\w*)\s*(?:=|=>|<=|>=|<|>)\s*\d+/i';
        if (preg_match_all($pattern, $code, $matches) > 0) {
            foreach ($matches[0] as $hit) {
                $problems[] = 'littéral de seuil : ' . trim($hit);
            }
        }

        return $problems;
    }

    /** @return list<string> */
    public static function guardProblems(string $path, string $method, string $handlerClass, bool $auth): array
    {
        $problems = [];
        if ($handlerClass !== GatedAction::class) {
            $problems[] = $method . ' ' . $path . ' : enregistrée hors NurseryRoutes (sans porte du droit d\'usage)';
        }
        if (!str_starts_with($path, '/api/v1/nursery/')) {
            $problems[] = $method . ' ' . $path . ' : préfixe /nursery attendu';
        }
        if (!$auth) {
            $problems[] = $method . ' ' . $path . ' : route publique interdite';
        }

        return $problems;
    }

    /**
     * @param int $today AAAAMMJJHHMM de la date du jour
     * @return list<string>
     */
    public static function migrationProblems(string $relativePath, string $sql, int $today): array
    {
        $problems = [];
        if (preg_match('#^src/Modules/Apps/Nursery/\w+/Database/Migrations/(\d{12})_nursery_[a-z0-9_]+\.sql$#', $relativePath, $m) !== 1) {
            return [$relativePath . ' : nom attendu AAAAMMJJHHMM_nursery_<module>_<objet>.sql dans <Module>/Database/Migrations'];
        }
        $stamp = (int) $m[1];
        if ($stamp > $today) {
            $problems[] = $relativePath . ' : datée dans le futur';
        }
        if ($stamp < self::FIRST_MIGRATION) {
            $problems[] = $relativePath . ' : horodatage antérieur à la limite de départ';
        }
        $clean = (string) preg_replace('/^\s*--.*$/m', '', $sql);
        foreach (['FOREIGN KEY', 'REFERENCES ', 'AUTO_INCREMENT', 'AUTOINCREMENT', 'ENGINE=', 'ENGINE ='] as $needle) {
            if (stripos($clean, $needle) !== false) {
                $problems[] = $relativePath . ' : ' . $needle . ' interdit (SQL portable, aucune clé étrangère)';
            }
        }
        foreach (array_filter(array_map('trim', explode(';', $clean))) as $statement) {
            if (preg_match('/^CREATE TABLE(?: IF NOT EXISTS)?\s+`?(\w+)`?\s*\((.*)\)\s*$/is', $statement, $t) === 1) {
                $table = $t[1];
                if (!str_starts_with($table, 'nu_')) {
                    $problems[] = $relativePath . ' : table ' . $table . ' sans préfixe nu_';
                }
                if (preg_match('/^\s*tenant_id\s+VARCHAR\(40\)\s+NOT NULL/im', $t[2]) !== 1) {
                    $problems[] = $relativePath . ' : ' . $table . '.tenant_id VARCHAR(40) NOT NULL obligatoire';
                }
                if (preg_match('/^\s*establishment_id\s+VARCHAR\(40\)\s+NOT NULL/im', $t[2]) !== 1) {
                    $problems[] = $relativePath . ' : ' . $table . '.establishment_id VARCHAR(40) NOT NULL obligatoire';
                }
                foreach (preg_split('/,\s*\n/', $t[2]) ?: [] as $definition) {
                    if (preg_match('/^\s*`?([a-z_][a-z0-9_]*)`?\s+(?:CHAR|VARCHAR|INTEGER|SMALLINT|TEXT|TINYINT|BIGINT|DECIMAL)/i', $definition, $c) !== 1) {
                        continue;
                    }
                    $column = strtolower($c[1]);
                    if (in_array($column, self::MYSQL_RESERVED, true)) {
                        $problems[] = $relativePath . ' : colonne ' . $table . '.' . $column . ' est un mot réservé MySQL';
                    }
                    if (in_array($column, self::FORBIDDEN_COLUMNS, true)) {
                        $problems[] = $relativePath . ' : colonne de note interdite (' . $table . '.' . $column . ', RG-MAT-1)';
                    }
                }
            }
        }

        return $problems;
    }

    public function testLeManifesteResteComingSoonEtLesPermissionsSontPrefixees(): void
    {
        self::assertSame('coming_soon', NurseryApplication::manifest()->status);
        self::assertSame('platform.applications.nursery', NurseryApplication::CONTAINER_KEY);
        self::assertSame('nursery', NurseryApplication::manifest()->key);
        self::assertSame(30000, NurseryApplication::manifest()->monthlyPriceXaf);
        foreach (NurseryApplication::permissions() as $permission) {
            self::assertStringStartsWith('nursery.', $permission);
        }
        foreach (NurseryApplication::roles() as $role) {
            self::assertStringStartsWith('nursery_', $role);
        }
        foreach (['NurseryApplication.php', 'definitions.php', 'routes.php', 'Support', 'Policies'] as $item) {
            self::assertFileExists(self::appRoot() . '/' . $item);
        }
    }

    public function testLeCodeNeDependQueDesContratsDuSocleEtDeLuiMeme(): void
    {
        $offenders = [];
        foreach (self::files() as $file) {
            $source = (string) file_get_contents($file);
            if (!str_contains($source, 'declare(strict_types=1);')) {
                $offenders[] = basename($file) . ' : sans declare(strict_types=1)';
            }
            foreach (self::dependencyProblems($source) as $problem) {
                $offenders[] = basename($file) . ' : ' . $problem;
            }
            foreach (['Illuminate\\', 'Laravel\\', 'Eloquent', 'PublicNursery'] as $needle) {
                if (str_contains($source, $needle)) {
                    $offenders[] = basename($file) . ' : ' . $needle;
                }
            }
        }

        self::assertSame([], $offenders);
    }

    public function testLesControlesDeDependanceDetectentUneViolation(): void
    {
        self::assertNotSame([], self::dependencyProblems('use Scholaris\\Modules\\Apps\\PublicNursery\\Support\\GatedAction;'));
        self::assertNotSame([], self::dependencyProblems('use Scholaris\\Modules\\Apps\\Primary\\Support\\Store;'));
        self::assertNotSame([], self::dependencyProblems('use Scholaris\\Modules\\Socle\\People\\Application\\PersonService;'));
        self::assertNotSame([], self::dependencyProblems('SELECT * FROM authorized_pickups WHERE 1'));
        self::assertSame([], self::dependencyProblems('use Scholaris\\Modules\\Socle\\People\\Contracts\\PersonDirectory; use Scholaris\\Modules\\Apps\\Nursery\\Support\\Store;'));
    }

    public function testToutesLesRoutesPassentParLaPorteDuDroitDUsage(): void
    {
        $registry = new RouteRegistry('/api/v1');
        (require self::appRoot() . '/routes.php')($registry);
        $problems = [];
        $writes = 0;
        foreach ($registry->all() as $route) {
            $problems = array_merge($problems, self::guardProblems($route['path'], $route['method'], $route['handler'][0], ($route['options']['auth'] ?? true) === true));
            if ($route['handler'][0] !== GatedAction::class) {
                continue;
            }
            [$mode, $class, $method] = GatedAction::decode($route['handler'][1]);
            if ($mode !== ($route['method'] === 'GET' ? 'read' : 'write')) {
                $problems[] = $route['method'] . ' ' . $route['path'] . ' : mode ' . $mode . ' incohérent (toute route non GET est en écriture)';
            }
            $route['method'] !== 'GET' && ++$writes;
            if (!method_exists($class, $method)) {
                $problems[] = $route['method'] . ' ' . $route['path'] . ' : ' . $class . '::' . $method . ' introuvable';
            }
        }

        self::assertNotSame([], $registry->all());
        self::assertGreaterThan(0, $writes);
        self::assertSame([], $problems);
    }

    public function testLeControleDeGardeEchoueSurUneRouteNonGardeeEtPubliqueOuHorsPrefixe(): void
    {
        self::assertNotSame([], self::guardProblems('/api/v1/nursery/children', 'GET', 'Autre\\Controleur', true));
        self::assertNotSame([], self::guardProblems('/api/v1/children', 'GET', GatedAction::class, true));
        self::assertNotSame([], self::guardProblems('/api/v1/nursery/children', 'GET', GatedAction::class, false));
        self::assertSame([], self::guardProblems('/api/v1/nursery/children', 'GET', GatedAction::class, true));
    }

    public function testLesSousModulesNEnregistrentPasDeRouteHorsNurseryRoutes(): void
    {
        $offenders = [];
        foreach (glob(self::appRoot() . '/*/routes.php') ?: [] as $file) {
            $source = (string) file_get_contents($file);
            if (!str_contains($source, 'NurseryRoutes $routes') || preg_match('/->add\(|RouteRegistry/', $source) === 1) {
                $offenders[] = basename(dirname($file)) . '/routes.php';
            }
        }

        self::assertSame([], $offenders, 'Chaque routes.php de sous-module reçoit un NurseryRoutes (porte du droit d\'usage automatique).');
    }

    public function testLesMigrationsSontPortablesCloisonneesEtJamaisDansLeFutur(): void
    {
        $today = (int) (new \DateTimeImmutable('now', new \DateTimeZone('Africa/Douala')))->format('YmdHi');
        $problems = [];
        $names = [];
        foreach (self::files('sql') as $file) {
            $relative = substr($file, strlen(str_replace(DIRECTORY_SEPARATOR, '/', self::root())) + 1);
            $problems = array_merge($problems, self::migrationProblems($relative, (string) file_get_contents($file), $today));
            $names[] = basename($file);
        }
        self::assertSame($names, array_values(array_unique($names)), 'Noms de migration en double.');
        self::assertSame([], $problems);
    }

    public function testLesControlesDeMigrationEchouentSurLesMauvaisExemples(): void
    {
        $today = 202610221200;
        $ok = "CREATE TABLE nu_demo (\n    id CHAR(26) NOT NULL PRIMARY KEY,\n    tenant_id VARCHAR(40) NOT NULL,\n    establishment_id VARCHAR(40) NOT NULL,\n    label VARCHAR(80) NOT NULL\n);";
        $path = 'src/Modules/Apps/Nursery/Core/Database/Migrations/202610220900_nursery_core_demo.sql';
        self::assertSame([], self::migrationProblems($path, $ok, $today));
        self::assertNotSame([], self::migrationProblems($path, str_replace('tenant_id VARCHAR(40) NOT NULL,', 'org VARCHAR(40) NOT NULL,', $ok), $today), 'table sans tenant_id');
        self::assertNotSame([], self::migrationProblems($path, str_replace('CREATE TABLE nu_demo', 'CREATE TABLE pn_demo', $ok), $today), 'mauvais préfixe');
        self::assertNotSame([], self::migrationProblems(str_replace('20261022', '20261023', $path), $ok, $today), 'migration datée dans le futur');
        self::assertNotSame([], self::migrationProblems($path, str_replace('label VARCHAR(80)', "score INTEGER NOT NULL,\n    label VARCHAR(80)", $ok), $today), 'colonne de note');
        self::assertNotSame([], self::migrationProblems($path, str_replace('label VARCHAR(80)', 'level VARCHAR(80)', $ok), $today), 'mot réservé');
        self::assertNotSame([], self::migrationProblems($path, $ok . "\nALTER TABLE nu_demo ADD FOREIGN KEY (id) REFERENCES autre (id);", $today), 'clé étrangère');
        self::assertNotSame([], self::migrationProblems('src/Modules/Apps/Nursery/Core/Database/Migrations/demo.sql', $ok, $today), 'nom de fichier');
    }

    public function testAucunSeuilNiDelaiNiReglePosesDansLeCode(): void
    {
        $offenders = [];
        foreach (self::files() as $file) {
            foreach (self::thresholdProblems((string) file_get_contents($file)) as $problem) {
                $offenders[] = basename($file) . ' : ' . $problem;
            }
        }
        self::assertSame([], $offenders, 'Seuils, délais et durées viennent de /settings (NurserySettings), jamais du code.');
    }

    public function testLeControleDeSeuilEchoueSurUnLitteral(): void
    {
        self::assertNotSame([], self::thresholdProblems('$lateAfterMinutes = 30;'));
        self::assertNotSame([], self::thresholdProblems('if ($minutes > 15) { alert(); }'));
        self::assertNotSame([], self::thresholdProblems('$maxAttempts = 5;'));
        self::assertSame([], self::thresholdProblems('$minutes = $settings->int($t, $e, NurserySettings::PICKUP_LATE_AFTER); // 30 min proposées en données'));
    }

    public function testAucuneRouteNiVariableDeNoteDansLeSuiviDuDeveloppement(): void
    {
        $registry = new RouteRegistry('/api/v1');
        (require self::appRoot() . '/routes.php')($registry);
        foreach ($registry->all() as $route) {
            self::assertDoesNotMatchRegularExpression('#/(score|scores|grade|grades|average|averages|rank|ranking|total|totals|mark|marks)(/|$|\{)#', $route['path'], $route['path']);
        }
    }
}
