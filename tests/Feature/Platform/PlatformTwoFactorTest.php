<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Scholaris\Core\Config\Config;
use Scholaris\Core\Database\Migrator;
use Scholaris\Core\Kernel;
use Scholaris\Core\RateLimit\Clock;
use Scholaris\Modules\Socle\Identity\Contracts\PlatformAdministrators;
use Scholaris\Modules\Socle\Identity\Contracts\RecentSecondFactor;
use Scholaris\Modules\Socle\Integrations\Contracts\MailGateway;
use Scholaris\Modules\Socle\Integrations\Contracts\SmsGateway;
use Scholaris\Modules\Socle\Platform\Console\AdminCreateCommand;
use Tests\Support\Identity\RecordingMail;
use Tests\Support\Identity\RecordingSms;
use Tests\Support\Identity\TestClock;

/**
 * Vraie pile Identity avec AUTH_2FA_REQUIRED=false : la double authentification reste OBLIGATOIRE pour l'administrateur
 * de plateforme (connexion, /me, preuve de 2FA récente, accès aux routes /platform), pas pour les autres rôles.
 */
final class PlatformTwoFactorTest extends TestCase
{
    private Kernel $kernel;

    protected function setUp(): void
    {
        $this->kernel = Kernel::boot(dirname(__DIR__, 3), Config::fromArray([
            'app' => ['name' => 'SCHOLARIS', 'debug' => false, 'timezone' => 'Africa/Douala', 'api_prefix' => '/api/v1', 'log_path' => 'storage/logs/test.log', 'url' => 'http://localhost', 'two_factor_required' => false],
            'database' => ['driver' => 'sqlite', 'database' => ':memory:'],
            'api' => ['cors_origins' => [], 'idempotent_paths' => [], 'rate_limits' => []],
            'identity' => ['argon_memory' => 1024, 'argon_time' => 1, 'secret_key' => 'cle-de-test-identity'],
        ]), [
            Clock::class => new TestClock(),
            SmsGateway::class => new RecordingSms(),
            MailGateway::class => new RecordingMail(),
        ]);
        $this->kernel->container()->get(Migrator::class)->migrate();
    }

    public function testLaDoubleAuthentificationEstForceePourLAdministrateurDePlateformeMemeDesactivee(): void
    {
        $admin = $this->kernel->container()->get(PlatformAdministrators::class)->create('emm.foka@gmail.com', 'Emmanuel', 'Foka Ndjock');

        $login = $this->login('emm.foka@gmail.com', $admin['password']);
        self::assertSame(200, $login[0]->getStatusCode(), (string) $login[0]->getBody());
        self::assertTrue($login[1]['two_factor_required'], 'AUTH_2FA_REQUIRED=false ne dispense pas l\'administrateur de plateforme.');
        $token = (string) $login[1]['token'];

        $me = $this->call('GET', '/api/v1/me', $token);
        self::assertSame(200, $me[0]->getStatusCode());
        self::assertTrue($me[1]['data']['two_factor']['required']);
        self::assertFalse($me[1]['data']['two_factor']['verified']);

        $dashboard = $this->call('GET', '/api/v1/platform/dashboard', $token);
        self::assertSame(403, $dashboard[0]->getStatusCode());
        self::assertSame('https://api.scholaris.cm/errors/two-factor-required', $dashboard[1]['type']);

        self::assertFalse($this->kernel->container()->get(RecentSecondFactor::class)->hasRecent('platform', $admin['user_id'], 900));

        // Vraies permissions d'Identity : rôle de plateforme sans aucune permission métier.
        $checker = $this->kernel->container()->get(\Scholaris\Core\Security\PermissionChecker::class);
        $principal = new \Scholaris\Core\Security\Principal($admin['user_id'], 'platform');
        self::assertTrue($checker->can($principal, 'platform.access'));
        self::assertTrue($checker->can($principal, 'platform.requests.decide'));
        self::assertFalse($checker->can($principal, 'billing.read'));
        self::assertFalse($checker->can($principal, 'applications.manage'));
    }

    public function testUnSuperAdministrateurDeClientNEstPasConcerneQuandLaDoubleAuthentificationEstDesactivee(): void
    {
        $result = (new \Scholaris\Core\Console\CreateAdministrator($this->kernel->container()->get(\Scholaris\Core\Database\ConnectionFactory::class), 'Africa/Douala'))
            ->create('Client Test', 'Awa', 'Ngo', 'awa@client.test');

        $login = $this->login('awa@client.test', $result['password']);
        self::assertSame(200, $login[0]->getStatusCode(), (string) $login[0]->getBody());
        self::assertFalse($login[1]['two_factor_required']);
        $userId = (string) $this->kernel->container()->get(\Scholaris\Core\Database\ConnectionFactory::class)->pdo()->query("SELECT id FROM users WHERE email = 'awa@client.test'")->fetchColumn();
        self::assertTrue($this->kernel->container()->get(RecentSecondFactor::class)->hasRecent((string) $result['tenant_id'], $userId, 900));

        $dashboard = $this->call('GET', '/api/v1/platform/dashboard', (string) $login[1]['token']);
        self::assertSame(403, $dashboard[0]->getStatusCode(), 'Un super administrateur de client n\'est pas administrateur de plateforme.');
    }

    public function testLaCommandeCreeLeCompteAvecUnNomEnPlusieursMots(): void
    {
        $command = $this->kernel->container()->get('console.command.platform:admin-create');
        self::assertInstanceOf(AdminCreateCommand::class, $command);

        ob_start();
        $code = $command->run(['+237690000000', 'Emmanuel', 'Foka', 'Ndjock']);
        $output = (string) ob_get_clean();

        self::assertSame(0, $code);
        self::assertStringContainsString('Emmanuel Foka Ndjock', $output);
        self::assertStringContainsString('OBLIGATOIRE', $output);
        $role = $this->kernel->container()->get(\Scholaris\Core\Database\ConnectionFactory::class)->pdo()
            ->query("SELECT role, tenant_id FROM role_assignments WHERE role = 'platform_admin'")->fetch();
        self::assertSame('platform', $role['tenant_id']);
    }

    /** @return array{0: ResponseInterface, 1: array<string, mixed>} */
    private function login(string $identifier, string $password): array
    {
        $response = $this->kernel->handle(new ServerRequest('POST', '/api/v1/auth/login', ['User-Agent' => 'Mozilla/5.0 Chrome/120.0', 'Content-Type' => 'application/json'], json_encode(['identifier' => $identifier, 'password' => $password], JSON_THROW_ON_ERROR)));

        return [$response, (array) json_decode((string) $response->getBody(), true)];
    }

    /** @return array{0: ResponseInterface, 1: array<string, mixed>} */
    private function call(string $method, string $path, string $token): array
    {
        $response = $this->kernel->handle(new ServerRequest($method, $path, ['Authorization' => 'Bearer ' . $token]));

        return [$response, (array) json_decode((string) $response->getBody(), true)];
    }
}
