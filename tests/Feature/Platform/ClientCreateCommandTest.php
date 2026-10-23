<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use PDO;
use PHPUnit\Framework\TestCase;
use Scholaris\Core\Config\Config;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Database\Migrator;
use Scholaris\Core\Kernel;
use Scholaris\Core\RateLimit\Clock;
use Scholaris\Modules\Socle\Integrations\Contracts\MailGateway;
use Scholaris\Modules\Socle\Integrations\Contracts\SmsGateway;
use Scholaris\Modules\Socle\Platform\Console\ClientCreateCommand;
use Tests\Support\Identity\RecordingMail;
use Tests\Support\Identity\RecordingSms;
use Tests\Support\Identity\TestClock;

/** `platform:client-create` : client, établissement, application en essai et administrateur invité en une commande (pilote). */
final class ClientCreateCommandTest extends TestCase
{
    private Kernel $kernel;
    private RecordingMail $mail;

    protected function setUp(): void
    {
        $this->mail = new RecordingMail();
        $this->kernel = Kernel::boot(dirname(__DIR__, 3), Config::fromArray([
            'app' => ['name' => 'SCHOLARIS', 'debug' => false, 'timezone' => 'Africa/Douala', 'api_prefix' => '/api/v1', 'log_path' => 'storage/logs/test.log', 'url' => 'https://scholaris.test', 'two_factor_required' => false],
            'database' => ['driver' => 'sqlite', 'database' => ':memory:'],
            'api' => ['cors_origins' => [], 'idempotent_paths' => [], 'rate_limits' => []],
            'identity' => ['argon_memory' => 1024, 'argon_time' => 1, 'secret_key' => 'cle-de-test-identity'],
        ]), [
            Clock::class => new TestClock(),
            SmsGateway::class => new RecordingSms(),
            MailGateway::class => $this->mail,
        ]);
        $this->kernel->container()->get(Migrator::class)->migrate();
    }

    private function pdo(): PDO
    {
        return $this->kernel->container()->get(ConnectionFactory::class)->pdo();
    }

    /** @param list<string> $args */
    private function execute(array $args): array
    {
        $command = $this->kernel->container()->get('console.command.' . ClientCreateCommand::NAME);
        self::assertInstanceOf(ClientCreateCommand::class, $command);
        ob_start();
        $code = $command->run($args);

        return [$code, (string) ob_get_clean()];
    }

    public function testCreeLeClientLEtablissementLEssaiEtInviteLAdministrateur(): void
    {
        [$code, $output] = $this->execute(['higher', 'higher', 'direction@institut-test.cm', 'Awa', 'Fictive', 'Institut', 'Supérieur', 'de', 'Test', '--ville=Garoua', '--region=Nord']);

        self::assertSame(0, $code, $output);
        self::assertStringContainsString('Client créé.', $output);
        $tenant = (string) $this->pdo()->query("SELECT id FROM tenants WHERE name = 'Institut Supérieur de Test'")->fetchColumn();
        self::assertNotSame('', $tenant);
        self::assertSame('higher', $this->pdo()->query("SELECT type FROM establishments WHERE tenant_id = '{$tenant}'")->fetchColumn());
        self::assertSame('trial', $this->pdo()->query("SELECT state FROM tenant_applications WHERE tenant_id = '{$tenant}' AND app_key = 'higher'")->fetchColumn());
        self::assertSame('pending', $this->pdo()->query("SELECT status FROM users WHERE tenant_id = '{$tenant}' AND email = 'direction@institut-test.cm'")->fetchColumn());
        self::assertSame('Garoua', $this->pdo()->query("SELECT city FROM platform_clients WHERE tenant_id = '{$tenant}'")->fetchColumn());
        self::assertNotSame([], $this->mail->sent, 'Invitation envoyée par e-mail.');
    }

    public function testSimulationNEcritRien(): void
    {
        $before = (int) $this->pdo()->query('SELECT COUNT(*) FROM tenants')->fetchColumn();
        [$code, $output] = $this->execute(['higher', 'higher', 'direction@institut-test.cm', 'Awa', 'Fictive', 'Institut', 'Test', '--dry-run']);

        self::assertSame(0, $code);
        self::assertStringContainsString('[SIMULATION]', $output);
        self::assertSame($before, (int) $this->pdo()->query('SELECT COUNT(*) FROM tenants')->fetchColumn());
    }

    public function testArgumentsIncompletsOuTypeInconnuRefuses(): void
    {
        self::assertNull(ClientCreateCommand::parse(['higher', 'higher', 'a@b.cm', 'Awa']));
        self::assertNull(ClientCreateCommand::parse(['higher', 'universite', 'a@b.cm', 'Awa', 'Fictive', 'Institut']));
        self::assertSame('Institut Les Aigles', ClientCreateCommand::parse(['higher', 'higher', 'a@b.cm', 'Awa', 'Fictive', '"Institut', 'Les', 'Aigles"'])['name'] ?? null);
    }
}
