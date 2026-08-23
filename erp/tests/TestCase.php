<?php

declare(strict_types=1);

namespace Scholaris\Tests;

use PDO;
use RuntimeException;
use Scholaris\Application;
use Scholaris\Auth\Auth;
use Scholaris\Database\Connection;
use Scholaris\Database\Migrator;
use Scholaris\Database\Table;
use Scholaris\Http\Request;
use Scholaris\Http\Response;
use Scholaris\Support\Env;

/**
 * Socle de tests, sans dependance externe.
 *
 * Chaque test part d'une base SQLite en memoire, migree avec le schema reel :
 * les tests s'executent donc contre les memes fichiers SQL que la production,
 * et une erreur de schema est detectee ici plutot qu'au deploiement.
 */
abstract class TestCase
{
    protected Application $app;

    protected Connection $db;

    protected string $tenantA;

    protected string $tenantB;

    private int $assertions = 0;

    /** @var list<string> */
    private array $failures = [];

    public function basePath(): string
    {
        return dirname(__DIR__);
    }

    protected function setUp(): void
    {
        // ":memory:" donne une base neuve par test, sans fichier a nettoyer.
        $this->db = Connection::sqlite(':memory:');

        (new Migrator($this->db, $this->basePath().'/database/migrations'))->migrate();

        $env = Env::load($this->basePath().'/tests/fixtures/.env.testing');
        $this->app = new Application($this->basePath(), $env, $this->db);
        $this->app->loadRoutes();

        $this->tenantA = $this->createTenant('AAA', 'Etablissement A');
        $this->tenantB = $this->createTenant('BBB', 'Etablissement B');
    }

    protected function createTenant(string $code, string $name): string
    {
        $id = Table::uuid();

        $this->db->execute(
            'INSERT INTO tenants (id, code, name, type, status, created_at, updated_at)
             VALUES (:id, :code, :name, :type, :status, :created_at, :updated_at)',
            [
                'id' => $id,
                'code' => $code,
                'name' => $name,
                'type' => 'SECONDAIRE',
                'status' => 'PRIVE',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]
        );

        return $id;
    }

    protected function createUser(string $tenantId, string $email, string $password = 'Test123!'): string
    {
        $id = Table::uuid();

        $this->db->execute(
            'INSERT INTO users (id, tenant_id, email, password_hash, first_name, last_name, status, created_at, updated_at)
             VALUES (:id, :tenant, :email, :hash, :first, :last, :status, :created_at, :updated_at)',
            [
                'id' => $id,
                'tenant' => $tenantId,
                'email' => $email,
                'hash' => Auth::hash($password),
                'first' => 'Test',
                'last' => 'User',
                'status' => 'ACTIVE',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]
        );

        return $id;
    }

    protected function createStudent(string $tenantId, string $matricule, string $lastName = 'Eleve'): string
    {
        $id = Table::uuid();

        $this->db->execute(
            'INSERT INTO students (id, tenant_id, matricule, first_name, last_name, date_of_birth, gender, nationality, status, created_at, updated_at)
             VALUES (:id, :tenant, :matricule, :first, :last, :dob, :gender, :nat, :status, :created_at, :updated_at)',
            [
                'id' => $id,
                'tenant' => $tenantId,
                'matricule' => $matricule,
                'first' => 'Prenom',
                'last' => $lastName,
                'dob' => '2010-01-01',
                'gender' => 'MALE',
                'nat' => 'Camerounaise',
                'status' => 'ACTIVE',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]
        );

        return $id;
    }

    /**
     * Cree un role portant les permissions demandees et l'attribue.
     *
     * @param  list<string>  $permissions  cles "resource:action"
     */
    protected function giveRole(string $userId, string $roleName, array $permissions): void
    {
        $roleId = Table::uuid();

        $this->db->execute(
            'INSERT INTO roles (id, tenant_id, name, is_system, created_at) VALUES (:id, NULL, :name, 0, :now)',
            ['id' => $roleId, 'name' => $roleName, 'now' => date('Y-m-d H:i:s')]
        );

        foreach ($permissions as $permission) {
            [$resource, $action] = explode(':', $permission, 2);

            $permissionId = $this->db->scalar(
                'SELECT id FROM permissions WHERE resource = :r AND action = :a',
                ['r' => $resource, 'a' => $action]
            );

            if ($permissionId === null) {
                $permissionId = Table::uuid();
                $this->db->execute(
                    'INSERT INTO permissions (id, resource, action) VALUES (:id, :r, :a)',
                    ['id' => $permissionId, 'r' => $resource, 'a' => $action]
                );
            }

            $this->db->execute(
                'INSERT INTO role_permissions (role_id, permission_id) VALUES (:role, :permission)',
                ['role' => $roleId, 'permission' => $permissionId]
            );
        }

        $this->db->execute(
            'INSERT INTO user_roles (user_id, role_id) VALUES (:user, :role)',
            ['user' => $userId, 'role' => $roleId]
        );
    }

    /** Ouvre une session applicative pour l'utilisateur donne. */
    protected function actingAs(string $userId): void
    {
        $user = $this->db->selectOne('SELECT * FROM users WHERE id = :id', ['id' => $userId]);

        if ($user === null) {
            throw new RuntimeException('Utilisateur de test introuvable.');
        }

        $_SESSION['user_id'] = $user['id'];
        $_SESSION['tenant_id'] = $user['tenant_id'];

        // Un compte de plateforme n'a pas d'etablissement : le contexte doit
        // rester vide, sans quoi le test ne refleterait pas la realite.
        $this->app->tenant()->clear();

        if ($user['tenant_id'] !== null) {
            $this->app->tenant()->set((string) $user['tenant_id']);
        }

        $this->app->auth()->restore();
        $this->app->rbac()->reset();
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function request(string $method, string $path, array $body = []): Response
    {
        // Le jeton CSRF est fourni automatiquement : les tests verifient le
        // comportement metier, et un test dedie couvre son absence.
        if ($method !== 'GET' && ! isset($body['_token'])) {
            $body['_token'] = $this->app->csrf()->token();
        }

        // La chaine de requete de l'URL doit alimenter $_GET, sinon les tests
        // de filtrage passeraient sans qu'aucun filtre ne soit applique.
        $query = [];
        $queryString = parse_url($path, PHP_URL_QUERY);

        if (is_string($queryString)) {
            parse_str($queryString, $query);
        }

        return $this->app->handle(new Request($query, $body, [
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $path,
            'REMOTE_ADDR' => '127.0.0.1',
        ]));
    }

    // --- Assertions ---------------------------------------------------------

    protected function assertTrue(bool $condition, string $message): void
    {
        $this->assertions++;

        if (! $condition) {
            $this->failures[] = $message;
        }
    }

    protected function assertSame(mixed $expected, mixed $actual, string $message): void
    {
        $this->assertions++;

        if ($expected !== $actual) {
            $this->failures[] = sprintf(
                '%s (attendu %s, obtenu %s)',
                $message,
                var_export($expected, true),
                var_export($actual, true)
            );
        }
    }

    protected function assertNull(mixed $value, string $message): void
    {
        $this->assertions++;

        if ($value !== null) {
            $this->failures[] = $message.' (valeur non nulle : '.var_export($value, true).')';
        }
    }

    protected function assertCount(int $expected, array $actual, string $message): void
    {
        $this->assertSame($expected, count($actual), $message);
    }

    /**
     * Compare deux montants numeriquement, a un centime pres.
     *
     * Indispensable pour les colonnes DECIMAL : SQLite renvoie "75000" la ou
     * MySQL renvoie "75000.00". Comparer les chaines rendrait les tests
     * dependants du moteur, et ferait echouer en local ce qui passe en
     * production, ou l'inverse.
     */
    protected function assertMoney(float $expected, mixed $actual, string $message): void
    {
        $this->assertions++;

        if (abs($expected - (float) $actual) > 0.005) {
            $this->failures[] = sprintf('%s (attendu %s, obtenu %s)', $message, $expected, var_export($actual, true));
        }
    }

    protected function assertStringContains(string $needle, string $haystack, string $message): void
    {
        $this->assertions++;

        if (! str_contains($haystack, $needle)) {
            $this->failures[] = $message." (chaine '{$needle}' absente)";
        }
    }

    /** Verifie qu'un appel leve bien une exception. */
    protected function assertThrows(callable $callback, string $message): void
    {
        $this->assertions++;

        try {
            $callback();
            $this->failures[] = $message.' (aucune exception levee)';
        } catch (\Throwable $e) {
            // Comportement attendu.
        }
    }

    /**
     * Execute toutes les methodes test* de la classe.
     *
     * @return array{name: string, assertions: int, failures: list<string>, tests: int}
     */
    public function run(): array
    {
        $methods = array_filter(
            get_class_methods($this),
            static fn (string $method): bool => str_starts_with($method, 'test')
        );

        $count = 0;

        foreach ($methods as $method) {
            $_SESSION = [];
            $this->setUp();

            try {
                $this->{$method}();
            } catch (\Throwable $e) {
                $this->failures[] = $method.' a leve '.get_class($e).' : '.$e->getMessage();
            }

            $count++;
        }

        return [
            'name' => static::class,
            'assertions' => $this->assertions,
            'failures' => $this->failures,
            'tests' => $count,
        ];
    }
}
