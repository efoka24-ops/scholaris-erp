<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Scholaris\Core\Http\RouteRegistry;
use Scholaris\Modules\Apps\Secondary\SecondaryApplication;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\Modules;
use Scholaris\Modules\Apps\Secondary\Setup\Http\ProfileController;
use Scholaris\Modules\Apps\Secondary\Support\GatedAction;
use Scholaris\Modules\Apps\Secondary\Support\PlatformAction;

/**
 * Conventions de l'application Enseignement secondaire (spec 005, plan §7, T811, T814, T958) : un seul produit (manifeste
 * `coming_soon`), contrats du socle seulement, tables `se_*` portables et cloisonnées (client et établissement), aucun mot réservé
 * MySQL, aucun seuil littéral hors classes `Defaults`, AUCUN code de type d'établissement lu ou testé hors
 * Setup/Application/ProfileResolver (D9), toute route gardée par le droit d'usage, toute route d'un sous-module de module
 * activable gardée par ModuleGate (RG-SEC-3). Chaque contrôle est une fonction pure sur du texte, éprouvée par un mauvais exemple.
 */
final class SecondaryConventionsTest extends TestCase
{
    /** Squelette livré le 23 octobre 2026 : aucune migration Secondary antérieure. */
    private const FIRST_MIGRATION = 202610221300;
    private const RESOLVER = 'Setup/Application/ProfileResolver.php';
    private const TYPE_CODES = ['leg', 'lycee_technique', 'ces', 'cetic', 'college_prive', 'lycee_prive', 'autre'];
    private const MYSQL_RESERVED = ['order', 'group', 'groups', 'key', 'keys', 'rank', 'range', 'rows', 'row', 'condition', 'option', 'match', 'lines', 'usage', 'interval', 'release', 'system', 'read', 'index', 'level', 'check', 'column', 'default', 'desc', 'describe', 'distinct', 'from', 'having', 'insert', 'into', 'join', 'limit', 'lock', 'primary', 'references', 'rename', 'replace', 'require', 'schema', 'select', 'set', 'show', 'table', 'to', 'trigger', 'unique', 'update', 'use', 'where', 'window', 'function', 'lag', 'lead', 'cube', 'empty', 'except', 'first_value', 'last_value', 'nth_value', 'ntile', 'of', 'over', 'recursive', 'row_number', 'dense_rank', 'percent_rank', 'cume_dist', 'lateral', 'generated', 'stored', 'virtual', 'write', 'year_month'];

    private static function root(): string
    {
        return str_replace(DIRECTORY_SEPARATOR, '/', dirname(__DIR__, 2));
    }

    private static function appRoot(): string
    {
        return self::root() . '/src/Modules/Apps/Secondary';
    }

    /** @return list<string> */
    private static function files(string $extension = 'php'): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::appRoot(), RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === $extension) {
                $files[] = str_replace(DIRECTORY_SEPARATOR, '/', $file->getPathname());
            }
        }
        sort($files);

        return $files;
    }

    private static function relative(string $file): string
    {
        return substr($file, strlen(self::appRoot()) + 1);
    }

    private static function code(string $source): string
    {
        return (string) preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m', '#^\s*\*.*$#m'], '', $source);
    }

    /** @return list<string> */
    public static function dependencyProblems(string $source): array
    {
        $problems = [];
        if (preg_match('/Scholaris\\\\Modules\\\\Apps\\\\(?!Secondary\b)\w+/', $source, $m) === 1) {
            $problems[] = 'dépend d\'une autre application : ' . $m[0];
        }
        if (preg_match('/Scholaris\\\\Modules\\\\Socle\\\\\w+\\\\(?!Contracts\\\\)/', $source, $m) === 1) {
            $problems[] = 'utilise le socle hors Contracts : ' . $m[0];
        }
        if (preg_match('/\b(?:FROM|JOIN|INTO|UPDATE)\s+(?!se_)(?:users|people|establishments|settings|enrollments|invoices|grades|assessments|results|attendance_\w+|structures)\b/i', $source, $m) === 1) {
            $problems[] = 'lit ou écrit la table d\'un autre module : ' . $m[0];
        }

        return $problems;
    }

    /**
     * D9 : le code de type n'est lu (`->subtype`, liste EstablishmentSubtypes), écrit en littéral ni comparé (`typeCode`) que dans
     * le résolveur de profil.
     *
     * @return list<string>
     */
    public static function typeCodeProblems(string $relativePath, string $source): array
    {
        if ($relativePath === self::RESOLVER) {
            return [];
        }
        $code = self::code($source);
        $problems = [];
        if (preg_match('/([\'"])(' . implode('|', self::TYPE_CODES) . ')\1/', $code, $m) === 1) {
            $problems[] = $relativePath . ' : code de type en littéral ' . $m[0];
        }
        if (preg_match('/->subtype\b|EstablishmentSubtypes/', $code, $m) === 1) {
            $problems[] = $relativePath . ' : lecture du type hors ProfileResolver (' . $m[0] . ')';
        }
        if (preg_match('/typeCode\s*(?:===|!==|==|!=|<=>)|(?:===|!==|==|!=)\s*\$\w+->typeCode|match\s*\(\s*\$\w+->typeCode|switch\s*\(\s*\$\w+->typeCode|in_array\(\s*\$\w+->typeCode/', $code, $m) === 1) {
            $problems[] = $relativePath . ' : test du code de type (' . trim($m[0]) . ')';
        }

        return $problems;
    }

    /** @return list<string> */
    public static function thresholdProblems(string $source): array
    {
        $problems = [];
        $pattern = '/\b(\w*(?:threshold|seuil|pass_?mark|coefficient|coef|weekly_hours|volume|min_?average|max_?average|appeal_days|delay|delai|grace)\w*)\s*(?:=|=>|<=|>=|<|>)\s*\d+/i';
        if (preg_match_all($pattern, self::code($source), $matches) > 0) {
            foreach ($matches[0] as $hit) {
                $problems[] = 'littéral de seuil : ' . trim($hit);
            }
        }

        return $problems;
    }

    /**
     * Aucun littéral numérique autre que 0 et 1 dans un dossier Domain, sauf dans une classe `*Defaults.php` (valeurs d'exemple
     * étiquetées, D12).
     *
     * @return list<string>
     */
    public static function domainLiteralProblems(string $relativePath, string $source): array
    {
        if (!str_contains($relativePath, '/Domain/') || str_ends_with($relativePath, 'Defaults.php')) {
            return [];
        }
        $problems = [];
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_LNUMBER, T_DNUMBER], true) && !in_array((float) $token[1], [0.0, 1.0], true)) {
                $problems[] = $relativePath . ' : littéral numérique ' . $token[1] . ' (à mettre en paramètre ou dans une classe Defaults)';
            }
        }

        return $problems;
    }

    /** @return list<string> */
    public static function migrationProblems(string $relativePath, string $sql, int $now): array
    {
        if (preg_match('#^src/Modules/Apps/Secondary/(\w+)/Database/Migrations/(\d{12})_secondary_([a-z0-9_]+)\.sql$#', $relativePath, $m) !== 1) {
            return [$relativePath . ' : nom attendu <Module>/Database/Migrations/AAAAMMJJHHMM_secondary_<module>_<objet>.sql'];
        }
        $problems = [];
        $stamp = (int) $m[2];
        if ($stamp <= self::FIRST_MIGRATION) {
            $problems[] = $relativePath . ' : horodatage antérieur au squelette';
        }
        if ($stamp > $now) {
            $problems[] = $relativePath . ' : datée dans le futur';
        }
        if (!str_starts_with($m[3], strtolower($m[1]) . '_')) {
            $problems[] = $relativePath . ' : le nom doit commencer par secondary_' . strtolower($m[1]) . '_';
        }
        $clean = (string) preg_replace('/^\s*--.*$/m', '', $sql);
        foreach (['FOREIGN KEY', 'REFERENCES ', 'AUTO_INCREMENT', 'AUTOINCREMENT', 'ENGINE'] as $needle) {
            if (stripos($clean, $needle) !== false) {
                $problems[] = $relativePath . ' : ' . trim($needle) . ' interdit';
            }
        }
        if (preg_match('/\b(?:INSERT INTO|UPDATE|DELETE FROM|ALTER TABLE)\s+(?!se_)(\w+)/i', $clean, $foreign) === 1) {
            $problems[] = $relativePath . ' : écrit dans une table hors application (' . $foreign[1] . ') : données du socle dans database/migrations';
        }
        foreach (array_filter(array_map('trim', explode(';', $clean))) as $statement) {
            if (preg_match('/^CREATE TABLE\s+`?(\w+)`?\s*\((.*)\)\s*$/is', $statement, $t) !== 1) {
                continue;
            }
            if (!str_starts_with($t[1], 'se_')) {
                $problems[] = $relativePath . ' : table ' . $t[1] . ' sans préfixe se_';
            }
            foreach (['tenant_id', 'establishment_id'] as $column) {
                if (preg_match('/^\s*' . $column . '\s+VARCHAR\(40\)\s+NOT NULL/im', $t[2]) !== 1) {
                    $problems[] = $relativePath . ' : ' . $t[1] . '.' . $column . ' VARCHAR(40) NOT NULL obligatoire';
                }
            }
            foreach (preg_split('/,\s*\n/', $t[2]) ?: [] as $definition) {
                if (preg_match('/^\s*`?([a-z_][a-z0-9_]*)`?\s+(?:CHAR|VARCHAR|INTEGER|SMALLINT|TEXT|TINYINT|BIGINT|DECIMAL)/i', $definition, $c) === 1
                    && in_array(strtolower($c[1]), self::MYSQL_RESERVED, true)) {
                    $problems[] = $relativePath . ' : colonne ' . $t[1] . '.' . $c[1] . ' est un mot réservé MySQL';
                }
            }
        }

        return $problems;
    }

    /**
     * @param array<string, list<string>> $ownedBy sous-module => modules dont l'un doit garder chaque route (Modules::OWNED_BY)
     * @return list<string>
     */
    public static function routeProblems(string $method, string $path, string $handlerClass, string $handlerName, bool $auth, array $ownedBy = Modules::OWNED_BY): array
    {
        if (!$auth) {
            return [$method . ' ' . $path . ' : route publique interdite'];
        }
        if (str_starts_with($path, '/api/v1/platform/secondary/')) {
            return $handlerClass === PlatformAction::class ? [] : [$method . ' ' . $path . ' : route de plateforme hors PlatformAction'];
        }
        if (!str_starts_with($path, '/api/v1/secondary/')) {
            return [$method . ' ' . $path . ' : préfixe /secondary ou /platform/secondary attendu'];
        }
        if ($handlerClass !== GatedAction::class) {
            return [$method . ' ' . $path . ' : enregistrée hors SecondaryRoutes (sans porte du droit d\'usage)'];
        }
        [$mode, $class, $action, $module] = GatedAction::decode($handlerName);
        $problems = [];
        if ($mode !== ($method === 'GET' ? 'read' : 'write')) {
            $problems[] = $method . ' ' . $path . ' : mode ' . $mode . ' incohérent';
        }
        if (!method_exists($class, $action)) {
            $problems[] = $method . ' ' . $path . ' : ' . $class . '::' . $action . ' introuvable';
        }
        $sub = explode('\\', substr($class, strlen('Scholaris\\Modules\\Apps\\Secondary\\')))[0];
        $owners = $ownedBy[$sub] ?? null;
        if ($owners !== null && !in_array($module, $owners, true)) {
            $problems[] = $method . ' ' . $path . ' : route du sous-module ' . $sub . ' sans garde de module (' . implode(' ou ', $owners) . ')';
        }

        return $problems;
    }

    public function testUnSeulProduitComingSoonEtPermissionsPrefixees(): void
    {
        $manifest = SecondaryApplication::manifest();
        self::assertSame(['secondary', 'secondary', 'coming_soon', 60000], [$manifest->key, $manifest->target, $manifest->status, $manifest->monthlyPriceXaf]);
        self::assertSame('platform.applications.secondary', SecondaryApplication::CONTAINER_KEY);
        self::assertNotSame([], SecondaryApplication::permissions());
        foreach (SecondaryApplication::permissions() as $permission) {
            self::assertStringStartsWith('secondary.', $permission);
        }
        self::assertCount(24, SecondaryApplication::roles());
        foreach (SecondaryApplication::roles() as $role) {
            self::assertStringStartsWith('sec_', $role);
        }
        foreach (['SecondaryApplication.php', 'definitions.php', 'routes.php', 'Support/WriteGate.php', 'Support/GatedAction.php', 'Support/ModuleGate.php', 'Support/SecondaryRoutes.php', 'Support/Perimeter.php', 'Support/ScopedByClass.php', 'Policies/RoleMatrix.php', 'Policies/ScopeResolver.php', self::RESOLVER] as $item) {
            self::assertFileExists(self::appRoot() . '/' . $item);
        }
        $definitions = require self::appRoot() . '/definitions.php';
        self::assertIsArray($definitions);
        foreach (array_keys($definitions) as $key) {
            self::assertFalse(is_string($key) && str_starts_with($key, 'platform.applications.') && $key !== SecondaryApplication::CONTAINER_KEY, 'Une seule clé de manifeste : ' . $key);
        }
    }

    public function testLeCodeNeDependQueDesContratsDuSocleEtDeLuiMeme(): void
    {
        $problems = [];
        foreach (self::files() as $file) {
            $source = (string) file_get_contents($file);
            if (!str_contains($source, 'declare(strict_types=1);')) {
                $problems[] = self::relative($file) . ' : sans declare(strict_types=1)';
            }
            foreach (self::dependencyProblems($source) as $problem) {
                $problems[] = self::relative($file) . ' : ' . $problem;
            }
        }
        self::assertSame([], $problems);
    }

    public function testAucunCodeDeTypeHorsDuResolveurDeProfil(): void
    {
        $problems = [];
        foreach (self::files() as $file) {
            $problems = array_merge($problems, self::typeCodeProblems(self::relative($file), (string) file_get_contents($file)));
        }
        self::assertSame([], $problems);
        self::assertStringContainsString('EstablishmentSubtypes', (string) file_get_contents(self::appRoot() . '/' . self::RESOLVER), 'Le résolveur lit le type par le contrat d\'Organisation.');
    }

    public function testAucunSeuilLitteralHorsDefaults(): void
    {
        $problems = [];
        foreach (self::files() as $file) {
            $source = (string) file_get_contents($file);
            foreach (self::thresholdProblems($source) as $problem) {
                $problems[] = self::relative($file) . ' : ' . $problem;
            }
            $problems = array_merge($problems, self::domainLiteralProblems(self::relative($file), $source));
        }
        self::assertSame([], $problems);
    }

    public function testLesMigrationsSontPortablesCloisonneesEtSansMotReserve(): void
    {
        $now = (int) (new \DateTimeImmutable('now', new \DateTimeZone('Africa/Douala')))->format('YmdHi');
        $problems = [];
        $names = [];
        $tables = 0;
        foreach (self::files('sql') as $file) {
            $sql = (string) file_get_contents($file);
            $problems = array_merge($problems, self::migrationProblems(substr($file, strlen(self::root()) + 1), $sql, $now));
            $names[] = basename($file);
            $tables += preg_match_all('/CREATE TABLE/i', $sql);
        }
        self::assertSame($names, array_values(array_unique($names)));
        self::assertGreaterThanOrEqual(5, $tables);
        self::assertSame([], $problems);
    }

    public function testToutesLesRoutesSontGardeesEtLesModulesParModuleGate(): void
    {
        $registry = new RouteRegistry('/api/v1');
        (require self::appRoot() . '/routes.php')($registry);
        $problems = [];
        $writes = 0;
        foreach ($registry->all() as $route) {
            $problems = array_merge($problems, self::routeProblems($route['method'], $route['path'], $route['handler'][0], $route['handler'][1], ($route['options']['auth'] ?? true) === true));
            if ($route['handler'][0] === PlatformAction::class) {
                self::assertFalse($route['options']['tenant'] ?? true, $route['path'] . ' : sans en-tête d\'établissement');
                [$class, $action] = PlatformAction::decode($route['handler'][1]);
                self::assertTrue(method_exists($class, $action), $class . '::' . $action);
            }
            $route['method'] !== 'GET' && ++$writes;
        }
        self::assertGreaterThan(5, count($registry->all()));
        self::assertGreaterThan(0, $writes);
        self::assertSame([], $problems);
    }

    public function testLesSousModulesNEnregistrentQueParSecondaryRoutes(): void
    {
        $offenders = [];
        foreach (glob(self::appRoot() . '/*/routes.php') ?: [] as $file) {
            $source = (string) file_get_contents($file);
            if (!str_contains($source, 'SecondaryRoutes $routes') || preg_match('/->add\(|RouteRegistry/', $source) === 1) {
                $offenders[] = basename(dirname($file)) . '/routes.php';
            }
        }
        self::assertSame([], $offenders);
    }

    public function testLesDetecteursEchouentSurUneViolation(): void
    {
        self::assertNotSame([], self::dependencyProblems('use Scholaris\\Modules\\Apps\\Primary\\Support\\Store;'));
        self::assertNotSame([], self::dependencyProblems('use Scholaris\\Modules\\Socle\\Organisation\\Infrastructure\\PdoSettingsRepository;'));
        self::assertNotSame([], self::dependencyProblems('SELECT * FROM establishments WHERE id = ?'));
        self::assertSame([], self::dependencyProblems('use Scholaris\\Modules\\Socle\\Organisation\\Contracts\\SettingsReader; SELECT * FROM se_stream_refs'));

        self::assertNotSame([], self::typeCodeProblems('Workshops/Application/X.php', '<?php if ($code === \'cetic\') { }'));
        self::assertNotSame([], self::typeCodeProblems('Fees/Application/X.php', '<?php $t = $info->subtype;'));
        self::assertNotSame([], self::typeCodeProblems('Fees/Application/X.php', '<?php return match ($profile->typeCode) { default => 1 };'));
        self::assertNotSame([], self::typeCodeProblems('Fees/Application/X.php', '<?php if ($profile->typeCode !== $x) {}'));
        self::assertSame([], self::typeCodeProblems(self::RESOLVER, '<?php if ($code === \'cetic\') { }'));
        self::assertSame([], self::typeCodeProblems('Setup/Http/X.php', '<?php $data = [\'subtype\' => $profile->typeCode]; // « l\'autre » cas'));

        self::assertNotSame([], self::thresholdProblems('<?php $passMark = 10;'));
        self::assertNotSame([], self::thresholdProblems('<?php if ($average >= 10 && $seuil > 8) {}'));
        self::assertNotSame([], self::domainLiteralProblems('Councils/Domain/SecondaryStrategy.php', '<?php return $x >= 10;'));
        self::assertSame([], self::domainLiteralProblems('Councils/Domain/Defaults.php', '<?php const PASS = 10;'));

        self::assertNotSame([], self::migrationProblems('src/Modules/Apps/Secondary/Setup/Database/Migrations/202610230900_secondary_setup_bad.sql', "CREATE TABLE se_bad (\n    id CHAR(26) NOT NULL PRIMARY KEY,\n    tenant_id VARCHAR(40) NOT NULL,\n    rank INTEGER NOT NULL\n);", 202612312359));
        self::assertNotSame([], self::migrationProblems('src/Modules/Apps/Secondary/Setup/Database/Migrations/202610010000_secondary_setup_old.sql', '', 202612312359));
        self::assertNotSame([], self::migrationProblems('src/Modules/Apps/Secondary/Setup/Database/Migrations/202610230900_secondary_setup_seed.sql', "INSERT INTO settings (id) VALUES ('x');", 202612312359));

        self::assertNotSame([], self::routeProblems('GET', '/api/v1/secondary/x', 'Autre\\Controleur', 'x', true));
        self::assertNotSame([], self::routeProblems('GET', '/api/v1/other/x', GatedAction::class, 'x', true));
        self::assertNotSame([], self::routeProblems('GET', '/api/v1/platform/secondary/x', GatedAction::class, 'x', true));
        self::assertNotSame([], self::routeProblems('GET', '/api/v1/secondary/x', GatedAction::class, 'x', false));
        $unguarded = GatedAction::encode('read', [ProfileController::class, 'show']);
        $guarded = GatedAction::encode('read', [ProfileController::class, 'show'], Modules::WORKSHOPS);
        self::assertNotSame([], self::routeProblems('GET', '/api/v1/secondary/x', GatedAction::class, $unguarded, true, ['Setup' => [Modules::WORKSHOPS]]), 'Route de module sans ModuleGate.');
        self::assertSame([], self::routeProblems('GET', '/api/v1/secondary/x', GatedAction::class, $guarded, true, ['Setup' => [Modules::WORKSHOPS]]));
        self::assertNotSame([], self::routeProblems('POST', '/api/v1/secondary/x', GatedAction::class, $guarded, true), 'POST en mode lecture.');
    }
}
