<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Scholaris\Core\Http\RouteRegistry;
use Scholaris\Modules\Apps\PublicPrimary\PublicPrimaryApplication;
use Scholaris\Modules\Apps\PublicSchools\PublicSchoolsApplication;
use Scholaris\Modules\Apps\PublicSchools\Support\GatedAction;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationManifest;

/**
 * Conventions des écoles publiques (T623, SC-02..04), vérifiées sur src/Modules/Apps/PublicSchools et PublicPrimary, sous-modules
 * compris (les dossiers à venir sont couverts dès leur création) :
 *  - seuls les Contracts du socle ; aucun Laravel ; typage strict ;
 *  - accès inter-modules par `Contracts` seulement (exception : PublicSchools/Contracts), y compris entre applications ;
 *  - tables `pub_` (PublicSchools) et `pp_` (PublicPrimary) avec `tenant_id`, aucune table d'un autre module lue ou écrite ;
 *  - clés de conteneur de la forme `<préfixe>.<application>` ; manifestes `coming_soon` ;
 *  - toute route non GET passe par WriteGate (PublicRoutes/GatedAction) ;
 *  - aucun littéral de seuil dans `Domain/` : les règles sont des paramètres (PP16) ;
 *  - aucune colonne `apee` hors `PublicSchools/Apee` (RG-ELV-1) et aucune variable `apee_*` dans les modèles de documents d'élève.
 */
final class PublicSchoolsConventionsTest extends TestCase
{
    private const ROOT = '/src/Modules/Apps';
    private const APPLICATIONS = ['PublicSchools', 'PublicPrimary'];
    private const SHARED_FOLDERS = ['Contracts', 'Support', 'Policies'];

    /**
     * Seules références permises d'une autre application vers PublicSchools : ses Contracts (règle), plus le routage gardé, la matrice
     * des rôles et le manifeste, qui sont le mécanisme commun des applications publiques (WriteGate, RG-TER).
     */
    private const SHARED_ALLOWED = ['Contracts\\', 'Support\\PublicRoutes', 'Policies\\RoleMatrix', 'PublicSchoolsApplication'];

    /** Littéraux numériques tolérés dans Domain/ (neutres : indices, bornes triviales, pourcentage). */
    private const NEUTRAL_NUMBERS = ['0', '1', '2', '100'];

    /** Routes transitoires hors garde : ancien registre, supprimé par T625 (avec l'exception du routeur racine). */
    private const LEGACY_PREFIX = '/api/v1/public-primary/registry/';

    private static function base(): string
    {
        return str_replace(DIRECTORY_SEPARATOR, '/', dirname(__DIR__, 2));
    }

    /** @return list<string> */
    private static function files(string $application, string $extension): array
    {
        $files = [];
        $root = self::base() . self::ROOT . '/' . $application;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
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
        return substr($path, strlen(self::base() . self::ROOT) + 1);
    }

    /** @return array<string, string> chemin relatif => SQL sans commentaires, pour les migrations d'une application */
    private static function migrations(string $application): array
    {
        $sql = [];
        foreach (self::files($application, 'sql') as $path) {
            $sql[self::relative($path)] = (string) preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($path));
        }

        return $sql;
    }

    /** Module d'un fichier : premier dossier sous l'application ("" pour un fichier racine). */
    private static function moduleOf(string $relative): string
    {
        $parts = explode('/', $relative);

        return count($parts) > 2 ? $parts[1] : '';
    }

    public function testAucunUseDuSocleHorsContractsNiDeLaravelEtTypageStrict(): void
    {
        $offenders = [];
        foreach (self::APPLICATIONS as $application) {
            foreach (self::files($application, 'php') as $file) {
                $source = (string) file_get_contents($file);
                if (preg_match('/Scholaris\\\\Modules\\\\Socle\\\\\w+\\\\(?!Contracts\\\\)/', $source, $m) === 1
                    || preg_match('/\b(?:Illuminate|Laravel)\\\\|\buse\s+App\\\\|\bArtisan::|\bEloquent\b/', $source, $m) === 1) {
                    $offenders[] = self::relative($file) . ' : ' . $m[0];
                }
                if (!str_contains($source, 'declare(strict_types=1);')) {
                    $offenders[] = self::relative($file) . ' : sans declare(strict_types=1)';
                }
            }
        }

        self::assertSame([], $offenders);
    }

    public function testLAccesInterModulesNePasseQueParLesContracts(): void
    {
        $offenders = [];
        foreach (self::APPLICATIONS as $application) {
            foreach (self::files($application, 'php') as $file) {
                $relative = self::relative($file);
                $module = self::moduleOf($relative);
                $source = (string) file_get_contents($file);
                // Entre sous-modules d'une même application : Contracts, Support et Policies partagés seulement.
                if (preg_match_all('/Scholaris\\\\Modules\\\\Apps\\\\' . $application . '\\\\(\w+)\\\\/', $source, $matches) > 0) {
                    foreach ($matches[1] as $target) {
                        if ($target !== $module && !in_array($target, self::SHARED_FOLDERS, true) && $module !== '' && !($application === 'PublicPrimary' && $target === 'Registry')) {
                            $offenders[] = $relative . ' -> ' . $application . '\\' . $target . ' (sous-module voisin : passer par un Contract)';
                        }
                    }
                }
                // PublicPrimary vers PublicSchools : Contracts (et le mécanisme commun listé) seulement.
                if ($application === 'PublicPrimary' && preg_match_all('/Scholaris\\\\Modules\\\\Apps\\\\PublicSchools\\\\([\w\\\\]+)/', $source, $matches) > 0) {
                    foreach ($matches[1] as $target) {
                        if (!self::isSharedAllowed($target)) {
                            $offenders[] = $relative . ' -> PublicSchools\\' . $target;
                        }
                    }
                }
                // PublicSchools ne dépend jamais d'une application de plus haut niveau (hors fabrique de GatedAction, qui n'importe aucune classe).
                if ($application === 'PublicSchools' && preg_match('/use\s+Scholaris\\\\Modules\\\\Apps\\\\(PublicPrimary|PublicNursery)\\\\/', $source, $m) === 1) {
                    $offenders[] = $relative . ' -> ' . $m[1] . ' (PublicSchools ne dépend d\'aucune application)';
                }
            }
        }

        self::assertSame([], $offenders);
    }

    public function testLaPublicNurseryNAtteintPublicSchoolsQueParSesContracts(): void
    {
        $offenders = [];
        $dir = self::base() . self::ROOT . '/PublicNursery';
        if (is_dir($dir)) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                if (preg_match_all('/Scholaris\\\\Modules\\\\Apps\\\\PublicSchools\\\\([\w\\\\]+)/', (string) file_get_contents($file->getPathname()), $matches) > 0) {
                    foreach ($matches[1] as $target) {
                        if (!self::isSharedAllowed($target)) {
                            $offenders[] = $file->getFilename() . ' -> PublicSchools\\' . $target;
                        }
                    }
                }
            }
        }

        self::assertSame([], $offenders);
    }

    private static function isSharedAllowed(string $target): bool
    {
        foreach (self::SHARED_ALLOWED as $allowed) {
            if (str_starts_with($target, $allowed)) {
                return true;
            }
        }

        return false;
    }

    public function testLesContractsNeContiennentQueDesInterfacesEtDesObjetsDeValeur(): void
    {
        $contracts = glob(self::base() . self::ROOT . '/PublicSchools/Contracts/*.php') ?: [];
        $names = array_map(static fn (string $f): string => basename($f, '.php'), $contracts);
        foreach (['SchoolRegistry', 'PupilRegistry', 'ClassRoster', 'PupilTransfer', 'CensusSectionProvider', 'PublicMessages'] as $interface) {
            self::assertContains($interface, $names);
            self::assertTrue(interface_exists('Scholaris\\Modules\\Apps\\PublicSchools\\Contracts\\' . $interface), $interface);
        }
        $offenders = [];
        foreach ($contracts as $file) {
            $source = (string) file_get_contents($file);
            if (preg_match('/Scholaris\\\\Modules\\\\Apps\\\\PublicSchools\\\\(?!Contracts\\b)/', $source) === 1) {
                $offenders[] = basename($file) . ' : dépend de l\'intérieur de PublicSchools';
            }
            if (preg_match('/\b(PDO|ConnectionFactory|SELECT|INSERT)\b/', $source) === 1) {
                $offenders[] = basename($file) . ' : un contrat ne porte aucun accès aux données';
            }
            if (preg_match('/^\s*(?:public |protected |private )?function\s+\w+\([^)]*\)[^;{]*\{/m', $source) === 1 && !str_contains($source, 'function __construct')) {
                $offenders[] = basename($file) . ' : logique dans un contrat';
            }
        }

        self::assertSame([], $offenders);
    }

    public function testLesTablesSontPrefixeesEtPortentLeClient(): void
    {
        $problems = [];
        foreach ([['PublicSchools', 'pub_', 'public_schools'], ['PublicPrimary', 'pp_', 'public_primary']] as [$application, $prefix, $slug]) {
            foreach (self::migrations($application) as $file => $sql) {
                if (str_starts_with($file, 'PublicPrimary/Registry/')) {
                    continue; // ancien registre : supprimé par T625
                }
                if (preg_match('#^\w+/\w+/Database/Migrations/\d{12}_' . $slug . '_[a-z0-9_]+\.sql$#', $file) !== 1) {
                    $problems[] = $file . ' : nom attendu AAAAMMJJHHMM_' . $slug . '_<objet>.sql';
                }
                foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
                    if (preg_match('/^CREATE TABLE(?: IF NOT EXISTS)?\s+`?(\w+)`?\s*\((.*)\)\s*$/is', $statement, $m) === 1) {
                        if (!str_starts_with($m[1], $prefix)) {
                            $problems[] = $file . ' : table ' . $m[1] . ' sans préfixe ' . $prefix;
                        }
                        if (preg_match('/^\s*tenant_id\s+VARCHAR\(40\)\s+NOT NULL/im', $m[2]) !== 1) {
                            $problems[] = $file . ' : ' . $m[1] . '.tenant_id VARCHAR(40) NOT NULL obligatoire';
                        }
                    }
                    if (preg_match('/^(?:ALTER TABLE|INSERT INTO|UPDATE|DELETE FROM|DROP TABLE(?: IF EXISTS)?|CREATE (?:UNIQUE )?INDEX \w+ ON)\s+`?(\w+)`?/i', $statement, $m) === 1
                        && !str_starts_with($m[1], $prefix)) {
                        $problems[] = $file . ' : modifie la table ' . $m[1] . ' d\'un autre module';
                    }
                    foreach (['AUTO_INCREMENT', 'AUTOINCREMENT', 'ENGINE', 'FOREIGN KEY', 'REFERENCES'] as $word) {
                        if (stripos($statement, $word) !== false) {
                            $problems[] = $file . ' : ' . $word . ' (non portable ou clé étrangère interdite)';
                        }
                    }
                }
            }
        }

        self::assertSame([], $problems);
    }

    public function testLeCodeNeLitNiNEcritAucuneTableEtrangere(): void
    {
        $offenders = [];
        foreach ([['PublicSchools', 'pub_'], ['PublicPrimary', 'pp_']] as [$application, $prefix]) {
            foreach (self::files($application, 'php') as $file) {
                if (str_starts_with(self::relative($file), 'PublicPrimary/Registry/')) {
                    continue;
                }
                foreach (token_get_all((string) file_get_contents($file)) as $token) {
                    if (!is_array($token) || !in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                        continue;
                    }
                    if (preg_match_all('/\b(?:FROM|JOIN|INTO|UPDATE)\s+`?([a-z][a-z0-9_]*)`?/', $token[1], $m) > 0) {
                        foreach ($m[1] as $table) {
                            if (!str_starts_with($table, $prefix)) {
                                $offenders[] = self::relative($file) . ' : ' . $table;
                            }
                        }
                    }
                }
            }
        }

        self::assertSame([], $offenders, 'Les tables des autres modules passent par les Contracts du socle.');
    }

    public function testLesClesDeConteneurPortentLApplication(): void
    {
        $problems = [];
        foreach ([['PublicSchools', ['public_schools', 'public']], ['PublicPrimary', ['public_primary']]] as [$application, $segments]) {
            $definitions = require self::base() . self::ROOT . '/' . $application . '/definitions.php';
            self::assertIsArray($definitions);
            foreach (array_keys($definitions) as $key) {
                if (!is_string($key) || !str_contains($key, '.') || str_contains($key, '\\')) {
                    continue; // clé de classe
                }
                if (preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/', $key) !== 1 || array_intersect(explode('.', $key), $segments) === []) {
                    $problems[] = $application . ' : clé ' . $key . ' (forme attendue <préfixe>.<application>)';
                }
            }
        }

        self::assertSame([], $problems);
    }

    public function testLesManifestesSontExposesEtComingSoon(): void
    {
        foreach ([['PublicSchools', PublicSchoolsApplication::CONTAINER_KEY, 'public_schools'], ['PublicPrimary', PublicPrimaryApplication::CONTAINER_KEY, 'public_primary']] as [$application, $key, $slug]) {
            $definitions = require self::base() . self::ROOT . '/' . $application . '/definitions.php';
            self::assertInstanceOf(ApplicationManifest::class, $definitions[$key] ?? null);
            self::assertSame($slug, $definitions[$key]->key);
            self::assertSame(ApplicationManifest::COMING_SOON, $definitions[$key]->status, 'L\'application reste « bientôt » au catalogue tant qu\'elle n\'est pas livrée.');
            foreach (array_keys($definitions) as $other) {
                self::assertFalse(is_string($other) && str_starts_with($other, 'platform.applications.') && $other !== $key, 'Une seule clé de manifeste par application : ' . $other);
            }
        }
    }

    public function testToutesLesRoutesNonGetPassentParLaPorteWriteGate(): void
    {
        $registry = new RouteRegistry('/api/v1');
        foreach (self::APPLICATIONS as $application) {
            (require self::base() . self::ROOT . '/' . $application . '/routes.php')($registry);
        }
        $prefixes = ['PublicSchools' => '/api/v1/public/', 'PublicPrimary' => '/api/v1/public-primary/'];
        $problems = [];
        foreach ($registry->all() as $route) {
            $name = $route['method'] . ' ' . $route['path'];
            if (str_starts_with($route['path'], self::LEGACY_PREFIX)) {
                continue;
            }
            if (!str_starts_with($route['path'], $prefixes['PublicSchools']) && !str_starts_with($route['path'], $prefixes['PublicPrimary'])) {
                $problems[] = $name . ' : préfixe /public ou /public-primary attendu';
            }
            if ($route['handler'][0] !== GatedAction::class) {
                $problems[] = $name . ' : enregistrée hors PublicRoutes (sans WriteGate)';
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
                $problems[] = $name . ' : route sans authentification interdite';
            }
        }

        self::assertSame([], $problems);
    }

    public function testLesRoutesDesSousModulesNePassentQueParPublicRoutes(): void
    {
        $offenders = [];
        foreach (self::APPLICATIONS as $application) {
            foreach (self::files($application, 'php') as $file) {
                $relative = self::relative($file);
                if (preg_match('#^[^/]+/(\w+)/routes\.php$#', $relative, $m) === 1 && $m[1] !== 'Registry') {
                    $source = (string) file_get_contents($file);
                    if (preg_match('/RouteRegistry|->add\s*\(|->addRoute\s*\(/', $source) === 1 || !str_contains($source, 'PublicRoutes')) {
                        $offenders[] = $relative;
                    }
                }
                if (preg_match('#^[^/]+/.+/(routes|definitions)\.php$#', $relative, $m) === 1 && !str_contains($relative, '/Registry/')
                    && substr_count($relative, '/') > 2) {
                    $offenders[] = $relative . ' : un seul routes.php / definitions.php par sous-module, à sa racine';
                }
            }
        }

        self::assertSame([], $offenders);
    }

    public function testAucunLitteralDeSeuilDansLesDomaines(): void
    {
        $offenders = [];
        foreach (self::APPLICATIONS as $application) {
            foreach (self::files($application, 'php') as $file) {
                if (preg_match('#/Domain/#', $file) !== 1) {
                    continue;
                }
                foreach (self::thresholdLiterals((string) file_get_contents($file)) as $literal) {
                    $offenders[] = self::relative($file) . ' : ' . $literal;
                }
            }
        }

        self::assertSame([], $offenders, 'Les seuils et règles officielles sont des paramètres (SettingsReader), jamais des constantes du Domain.');
    }

    public function testLeDetecteurDeSeuilsVoitUnSeuilEnDur(): void
    {
        self::assertSame([], self::thresholdLiterals('<?php return $a >= 0 && $b === 1 ? 100 * $c / 2 : null;'));
        self::assertSame(['10'], self::thresholdLiterals('<?php return $average >= 10;'));
        self::assertSame(['0.5'], self::thresholdLiterals('<?php const RATIO = 0.5;'));
        self::assertSame(['35'], self::thresholdLiterals('<?php return $headcount > 35 ? "A" : "B";'));
    }

    /** @return list<string> littéraux numériques autres que les valeurs neutres */
    private static function thresholdLiterals(string $source): array
    {
        $found = [];
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_LNUMBER, T_DNUMBER], true) && !in_array($token[1], self::NEUTRAL_NUMBERS, true)) {
                $found[] = $token[1];
            }
        }

        return $found;
    }

    public function testAucuneColonneApeeHorsDuSousModuleApee(): void
    {
        $problems = [];
        $migrations = glob(self::base() . '/database/migrations/*.sql') ?: [];
        foreach (['src/Modules/*/*/Database/Migrations/*.sql', 'src/Modules/*/*/*/Database/Migrations/*.sql'] as $pattern) {
            $migrations = array_merge($migrations, glob(self::base() . '/' . $pattern) ?: []);
        }
        foreach ($migrations as $path) {
            $relative = substr(str_replace(DIRECTORY_SEPARATOR, '/', $path), strlen(self::base()) + 1);
            if (str_contains($relative, 'Apps/PublicSchools/Apee/')) {
                continue;
            }
            $sql = (string) preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($path));
            foreach (self::apeeColumns($sql) as $column) {
                $problems[] = $relative . ' : colonne ' . $column;
            }
        }

        self::assertSame([], $problems, 'RG-ELV-1 : aucun champ APEE hors du sous-module Apee.');
    }

    public function testLeDetecteurDeColonnesApeeEstEffectif(): void
    {
        self::assertSame(['apee_paid'], self::apeeColumns("CREATE TABLE pub_pupil_profiles (\n  id CHAR(26) NOT NULL PRIMARY KEY,\n  apee_paid INTEGER NOT NULL\n)"));
        self::assertSame(['apee_status'], self::apeeColumns('ALTER TABLE enrollments ADD COLUMN apee_status VARCHAR(10) NULL'));
        self::assertSame([], self::apeeColumns("INSERT INTO role_permissions (role, permission) VALUES ('pub_apee_board', 'public_schools.apee.read')"));
    }

    /** @return list<string> */
    private static function apeeColumns(string $sql): array
    {
        $found = [];
        $types = 'CHAR|VARCHAR|TEXT|LONGTEXT|INTEGER|INT|BIGINT|SMALLINT|TINYINT|DECIMAL|DATETIME|DATE|TIMESTAMP|BOOLEAN';
        if (preg_match_all('/^\s+`?(\w*apee\w*)`?\s+(?:' . $types . ')\b/im', $sql, $m) > 0) {
            array_push($found, ...$m[1]);
        }
        if (preg_match_all('/ADD COLUMN\s+`?(\w*apee\w*)`?/i', $sql, $m) > 0) {
            array_push($found, ...$m[1]);
        }

        return $found;
    }

    public function testAucuneVariableApeeDansLesModelesDeDocumentsDEleve(): void
    {
        $offenders = [];
        foreach (self::APPLICATIONS as $application) {
            foreach (['php', 'sql', 'html', 'twig', 'json', 'txt'] as $extension) {
                foreach (self::files($application, $extension) as $file) {
                    $relative = self::relative($file);
                    if (preg_match('#^\w+/Documents/#', $relative) !== 1 || stripos(basename($file), 'apee') !== false) {
                        continue; // seuls les documents propres à l'APEE (reçus DOC-26 et suivants, fichiers nommés *apee*) portent ces variables
                    }
                    if (self::hasApeeVariable((string) file_get_contents($file))) {
                        $offenders[] = $relative;
                    }
                }
            }
        }

        self::assertSame([], $offenders, 'Un document d\'élève ne porte jamais de variable apee_* (RG-ELV-1).');
    }

    public function testLeDetecteurDeVariablesApeeEstEffectif(): void
    {
        self::assertTrue(self::hasApeeVariable('Reçu {{apee_amount}} pour {{child_name}}'));
        self::assertTrue(self::hasApeeVariable("'variables' => ['child_name', 'apee_status']"));
        self::assertFalse(self::hasApeeVariable('Attestation de scolarité de {{child_name}}'));
    }

    private static function hasApeeVariable(string $content): bool
    {
        return preg_match('/\bapee_\w+/i', $content) === 1;
    }

    public function testLesSousModulesSontNommesSurLaListeDeLApplication(): void
    {
        self::assertNotSame([], PublicSchoolsApplication::MODULES);
        foreach ([['PublicSchools', PublicSchoolsApplication::MODULES], ['PublicPrimary', PublicPrimaryApplication::MODULES]] as [$application, $modules]) {
            foreach (glob(self::base() . self::ROOT . '/' . $application . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
                $name = basename($dir);
                if (in_array($name, self::SHARED_FOLDERS, true) || ($application === 'PublicPrimary' && $name === 'Registry')) {
                    continue;
                }
                self::assertContains($name, $modules, $application . '/' . $name . ' : sous-module non prévu (voir ' . $application . 'Application::MODULES)');
            }
        }
    }
}
