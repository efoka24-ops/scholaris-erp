<?php

declare(strict_types=1);

namespace Tests\Feature;

use InvalidArgumentException;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Scholaris\Core\Config\Config;
use Scholaris\Core\Console\CreateAdministrator;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Database\Migrator;
use Scholaris\Core\Kernel;

/** Amorçage du premier super administrateur, puis connexion réelle par l'API (sans doublures). */
final class CreateAdministratorTest extends TestCase
{
    private Kernel $kernel;
    private CreateAdministrator $creator;

    protected function setUp(): void
    {
        $this->kernel = Kernel::boot(dirname(__DIR__, 2), Config::fromArray([
            'app' => ['name' => 'SCHOLARIS', 'debug' => false, 'timezone' => 'Africa/Douala', 'api_prefix' => '/api/v1', 'log_path' => 'storage/logs/test.log'],
            'database' => ['driver' => 'sqlite', 'database' => ':memory:'],
            'api' => ['rate_limits' => []],
        ]));
        $this->kernel->container()->get(Migrator::class)->migrate();
        $this->creator = new CreateAdministrator($this->kernel->container()->get(ConnectionFactory::class));
    }

    public function testCreeLeClientLaPersonneLeCompteEtLeRole(): void
    {
        $result = $this->creator->create('Groupe Les Palmiers', 'Emmanuel', 'Foka', '699 12 34 56');
        $pdo = $this->kernel->container()->get(ConnectionFactory::class)->pdo();

        self::assertSame('+237699123456', $result['login']);
        self::assertSame(16, strlen($result['password']));
        self::assertSame('Groupe Les Palmiers', $pdo->query('SELECT name FROM tenants')->fetchColumn());
        self::assertSame('active', $pdo->query('SELECT status FROM users')->fetchColumn());
        self::assertSame('super_admin', $pdo->query('SELECT role FROM role_assignments')->fetchColumn());

        $hash = (string) $pdo->query('SELECT password_hash FROM users')->fetchColumn();
        self::assertStringStartsWith('$argon2id$', $hash);
        self::assertStringNotContainsString($result['password'], (string) json_encode($pdo->query('SELECT * FROM users')->fetchAll()));
    }

    public function testLeCompteCreeSeConnecteParLAPI(): void
    {
        $result = $this->creator->create('Groupe Les Palmiers', 'Emmanuel', 'Foka', 'emm@example.cm');
        $request = (new ServerRequest('POST', '/api/v1/auth/login', ['Content-Type' => 'application/json'], (string) json_encode([
            'identifier' => $result['login'],
            'password' => $result['password'],
        ])));

        $response = $this->kernel->handle($request);
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode(), $body);
        self::assertStringContainsString('two_factor', $body, 'Rôle sensible : la 2FA doit être exigée.');
    }

    public function testRefuseUnIdentifiantInvalideOuDejaUtilise(): void
    {
        $this->creator->create('Client A', 'A', 'A', '699000001');

        try {
            $this->creator->create('Client A', 'B', 'B', '699000001');
            self::fail('Doublon attendu.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('déjà utilisé', $e->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->creator->create('Client B', 'A', 'A', 'pas un identifiant');
    }
}
