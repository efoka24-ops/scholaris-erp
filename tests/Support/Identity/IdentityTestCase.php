<?php

declare(strict_types=1);

namespace Tests\Support\Identity;

use Nyholm\Psr7\ServerRequest;
use OTPHP\TOTP;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Scholaris\Core\Config\Config;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Database\Migrator;
use Scholaris\Core\Kernel;
use Scholaris\Core\RateLimit\Clock;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Identity\Application\Moment;
use Scholaris\Modules\Socle\Identity\Application\PasswordHasher;
use Scholaris\Modules\Socle\Identity\Contracts\AccountProvisioner;
use Scholaris\Modules\Socle\Identity\Domain\RoleAssignment;
use Scholaris\Modules\Socle\Identity\Domain\ScopeType;
use Scholaris\Modules\Socle\Identity\Infrastructure\PdoRoleAssignmentRepository;
use Scholaris\Modules\Socle\Identity\Infrastructure\PdoUserRepository;
use Scholaris\Modules\Socle\Integrations\Contracts\MailGateway;
use Scholaris\Modules\Socle\Integrations\Contracts\SmsGateway;
use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentDirectory;

/**
 * Base des tests d'Identity : vrais Authenticator, EstablishmentAccess et PermissionChecker du module ;
 * seuls l'annuaire des établissements, les passerelles SMS/e-mail et l'horloge sont des doublures.
 */
abstract class IdentityTestCase extends TestCase
{
    public const PASSWORD = 'Zebre-Orange-Lune-42';

    protected Kernel $kernel;
    protected TestClock $clock;
    protected RecordingSms $sms;
    protected RecordingMail $mail;
    protected InMemoryEstablishmentDirectory $directory;

    /** @param array<string, mixed> $overrides */
    protected function boot(array $overrides = []): void
    {
        $this->clock = new TestClock();
        $this->sms = new RecordingSms();
        $this->mail = new RecordingMail();
        $this->directory = new InMemoryEstablishmentDirectory();

        $config = Config::fromArray([
            'app' => ['name' => 'SCHOLARIS', 'debug' => false, 'timezone' => 'Africa/Douala', 'api_prefix' => '/api/v1', 'log_path' => 'storage/logs/test.log', 'url' => 'http://localhost'],
            'database' => ['driver' => 'sqlite', 'database' => ':memory:'],
            'api' => ['cors_origins' => [], 'idempotent_paths' => [], 'rate_limits' => []],
            'identity' => ['argon_memory' => 1024, 'argon_time' => 1, 'secret_key' => 'cle-de-test-identity'],
        ]);
        $this->kernel = Kernel::boot(dirname(__DIR__, 3), $config, array_merge([
            Clock::class => $this->clock,
            SmsGateway::class => $this->sms,
            MailGateway::class => $this->mail,
            EstablishmentDirectory::class => $this->directory,
        ], $overrides));
        $this->kernel->container()->get(Migrator::class)->migrate();
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>|null $body
     */
    protected function call(string $method, string $path, array $headers = [], ?array $body = null): ResponseInterface
    {
        return $this->kernel->handle(new ServerRequest($method, $path, $headers, $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR)));
    }

    /** @return array<string, mixed> */
    protected function json(ResponseInterface $response): array
    {
        return (array) json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    protected function pdo(): PDO
    {
        return $this->kernel->container()->get(ConnectionFactory::class)->pdo();
    }

    /**
     * Crée une personne avec compte actif et mot de passe, et ses rôles.
     *
     * @param list<array{0: string, 1: string, 2: ?string}> $roles [rôle, périmètre, identifiant de périmètre]
     * @return array{user_id: string, person_id: string}
     */
    protected function createUser(string $tenant, string $firstName, string $phone, array $roles = [], ?string $email = null, bool $active = true): array
    {
        $c = $this->kernel->container();
        $ids = $c->get(AccountProvisioner::class)->provision($tenant, $firstName, 'Test', $phone, $email);
        if ($active) {
            $c->get(PdoUserRepository::class)->setPassword($tenant, $ids['user_id'], $c->get(PasswordHasher::class)->hash(self::PASSWORD), $c->get(Moment::class)->iso());
        }
        foreach ($roles as [$role, $scope, $scopeId]) {
            $this->assignRole($tenant, $ids['person_id'], $role, $scope, $scopeId);
        }

        return $ids;
    }

    protected function assignRole(string $tenant, string $personId, string $role, string $scope, ?string $scopeId, ?string $endsAt = null, ?string $startsAt = null, ?string $delegatedBy = null): string
    {
        $moment = $this->kernel->container()->get(Moment::class);
        $id = (string) new Ulid();
        $this->kernel->container()->get(PdoRoleAssignmentRepository::class)->insert(new RoleAssignment(
            $id,
            $tenant,
            $personId,
            $role,
            ScopeType::from($scope),
            $scopeId,
            $scope === 'tenant' ? null : $scopeId,
            $startsAt ?? $moment->in(-60),
            $endsAt,
            $delegatedBy,
            null,
            $moment->iso(),
        ));

        return $id;
    }

    /**
     * @param array<string, mixed> $extra
     */
    protected function login(string $identifier, string $password = self::PASSWORD, array $extra = [], string $userAgent = 'Mozilla/5.0 (Windows NT 10.0) Chrome/120.0'): ResponseInterface
    {
        return $this->call('POST', '/api/v1/auth/login', ['User-Agent' => $userAgent], ['identifier' => $identifier, 'password' => $password] + $extra);
    }

    /** Connexion réussie sans 2FA exigée : renvoie le jeton. */
    protected function token(string $identifier, string $password = self::PASSWORD): string
    {
        $response = $this->login($identifier, $password);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $data = $this->json($response);

        return (string) $data['token'];
    }

    /** @return array<string, string> */
    protected function auth(string $token, ?string $establishment = null): array
    {
        $headers = ['Authorization' => 'Bearer ' . $token];
        if ($establishment !== null) {
            $headers['X-Establishment-Id'] = $establishment;
        }

        return $headers;
    }

    /** Code TOTP valable à l'instant de l'horloge de test. */
    protected function totp(string $secret, int $offsetSeconds = 0): string
    {
        return TOTP::createFromSecret($secret)->at($this->clock->now() + $offsetSeconds);
    }
}
