<?php

declare(strict_types=1);

namespace Tests\Support\Secondary;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Scholaris\Core\Config\Config;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Database\Migrator;
use Scholaris\Core\Kernel;
use Scholaris\Core\Security\Authenticator;
use Scholaris\Core\Security\EstablishmentAccess;
use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Modules\Apps\Secondary\Setup\Application\ProfileResolver;
use Scholaris\Modules\Apps\Secondary\Setup\Infrastructure\PdoProfileStore;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryClock;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Identity\Contracts\PermissionScopes;
use Scholaris\Modules\Socle\Identity\Contracts\Scope;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;
use Tests\Support\StubAccess;
use Tests\Support\StubAuthenticator;

/**
 * Base des tests de l'application secondaire : Kernel de test avec les vraies routes Secondary, base SQLite migrée (profils de
 * type et catalogue MINESEC amorcés par les migrations), doublures pour l'authentification (« Bearer utilisateur:client »), les
 * permissions, les périmètres, le droit d'usage et l'audit. Deux clients fictifs A et B, application ouverte (active) pour les deux.
 */
abstract class SecondaryTestCase extends TestCase
{
    public const TA = 'lycee-a';
    public const TB = 'lycee-b';
    public const ADMIN = 'admin-a';
    public const PLATFORM_ADMIN = 'super-admin';

    protected Kernel $kernel;
    protected StubAccess $access;
    protected SecondaryPermissions $permissions;
    protected SecondaryScopes $scopes;
    protected FakeApplicationAccess $applications;
    protected RecordingAuditLog $audit;
    protected ProfileFactory $factory;

    /** @param array<string, mixed> $overrides */
    protected function bootSecondary(array $overrides = []): void
    {
        $this->access = new StubAccess();
        $this->permissions = new SecondaryPermissions();
        $this->scopes = new SecondaryScopes();
        $this->applications = new FakeApplicationAccess();
        $this->audit = new RecordingAuditLog();
        $this->applications->set(self::TA, ApplicationAccess::ACTIVE);
        $this->applications->set(self::TB, ApplicationAccess::ACTIVE);

        $this->kernel = Kernel::boot(dirname(__DIR__, 3), Config::fromArray([
            'app' => ['name' => 'SCHOLARIS', 'debug' => false, 'timezone' => 'Africa/Douala', 'api_prefix' => '/api/v1', 'log_path' => 'storage/logs/test.log'],
            'database' => ['driver' => 'sqlite', 'database' => ':memory:'],
            'api' => ['cors_origins' => [], 'idempotent_paths' => [], 'rate_limits' => []],
        ]), $overrides + [
            Authenticator::class => new StubAuthenticator(),
            EstablishmentAccess::class => $this->access,
            PermissionChecker::class => $this->permissions,
            PermissionScopes::class => $this->scopes,
            ApplicationAccess::class => $this->applications,
            AuditLog::class => $this->audit,
            SecondaryClock::class => new SecondaryClock('2026-10-23'),
        ]);
        $this->kernel->container()->get(Migrator::class)->migrate();
        $this->factory = new ProfileFactory($this->pdo(), $this->service(ProfileResolver::class), $this->service(PdoProfileStore::class));
    }

    protected function tearDown(): void
    {
        unset($this->kernel, $this->access, $this->permissions, $this->scopes, $this->applications, $this->audit, $this->factory);
        gc_collect_cycles();
        parent::tearDown();
    }

    protected function pdo(): \PDO
    {
        return $this->service(ConnectionFactory::class)->pdo();
    }

    /** @param list<string> $permissions */
    protected function grant(string $tenantId, string $userId, string $establishmentId, array $permissions, string $scopeType = Scope::ESTABLISHMENT, ?string $scopeId = null): void
    {
        $this->access->grant($userId, $establishmentId);
        foreach ($permissions as $permission) {
            $this->permissions->grant($tenantId, $userId, $permission, $establishmentId);
            $this->scopes->add($tenantId, $userId, $establishmentId, $permission, new Scope($scopeType, $scopeId ?? $establishmentId, 'test_role'));
        }
    }

    protected function grantPlatformAdmin(string $userId = self::PLATFORM_ADMIN): void
    {
        foreach (['platform.access', 'platform.applications.manage'] as $permission) {
            $this->permissions->grant('platform', $userId, $permission);
        }
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array{0: int, 1: array<string, mixed>}
     */
    protected function call(string $method, string $path, ?array $body = null, string $userId = self::ADMIN, string $tenantId = self::TA, ?string $establishmentId = null): array
    {
        $response = $this->raw($method, $path, $body, $userId, $tenantId, $establishmentId);
        $decoded = json_decode((string) $response->getBody(), true);

        return [$response->getStatusCode(), is_array($decoded) ? $decoded : []];
    }

    /** @param array<string, mixed>|null $body */
    protected function raw(string $method, string $path, ?array $body = null, string $userId = self::ADMIN, string $tenantId = self::TA, ?string $establishmentId = null): ResponseInterface
    {
        $headers = ['Authorization' => 'Bearer ' . $userId . ':' . $tenantId];
        if ($establishmentId !== null) {
            $headers['X-Establishment-Id'] = $establishmentId;
        }
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }

        return $this->kernel->handle(new ServerRequest($method, '/api/v1' . $path, $headers, $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR)));
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    protected function ok(string $method, string $path, ?array $body = null, string $userId = self::ADMIN, string $tenantId = self::TA, ?string $establishmentId = null): array
    {
        [$status, $json] = $this->call($method, $path, $body, $userId, $tenantId, $establishmentId);
        self::assertContains($status, [200, 201], $method . ' ' . $path . ' => ' . $status . ' ' . json_encode($json));

        return is_array($json['data'] ?? null) ? $json['data'] : $json;
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    protected function service(string $class): object
    {
        /** @var T */
        return $this->kernel->container()->get($class);
    }
}
