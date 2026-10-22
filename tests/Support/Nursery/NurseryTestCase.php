<?php

declare(strict_types=1);

namespace Tests\Support\Nursery;

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
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Nursery\Support\NurseryClock;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Identity\Contracts\PermissionScopes;
use Scholaris\Modules\Socle\Messaging\Contracts\Messenger;
use Scholaris\Modules\Socle\Messaging\Contracts\SafetyMessenger;
use Scholaris\Modules\Socle\Organisation\Contracts\CurrentPeriod;
use Scholaris\Modules\Socle\Organisation\Contracts\StructureDraft;
use Scholaris\Modules\Socle\Organisation\Contracts\StructureWriter;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;
use Tests\Support\StubAccess;
use Tests\Support\StubAuthenticator;

/**
 * Base des tests de la Maternelle privée : Kernel de test avec les vraies routes Nursery, base SQLite migrée, sections créées par le
 * vrai StructureWriter, doublures déterministes pour l'authentification (« Bearer utilisateur:client »), les permissions et
 * périmètres, le droit d'usage, l'audit et les messageries. Données fictives (école SYN-NUR).
 *
 * Deux écoles privées : client A (école A, établissement E_A) et client B (école B, établissement E_B).
 */
abstract class NurseryTestCase extends TestCase
{
    public const TA = 'ecole-a';
    public const EA = 'e-ecole-a';
    public const TB = 'ecole-b';
    public const EB = 'e-ecole-b';
    public const DIRECTOR = 'directrice-a';
    public const DAY = '2026-10-21';

    protected Kernel $kernel;
    protected StubAccess $access;
    protected NurseryPermissions $permissions;
    protected FakeApplicationAccess $applications;
    protected RecordingAuditLog $audit;
    protected RecordingSafetyMessenger $safety;
    protected RecordingMessenger $messenger;
    protected NurseryWorld $world;
    protected FixedCurrentPeriod $periods;

    /** @param array<string, mixed> $overrides */
    protected function bootNursery(array $overrides = [], string $now = '2026-10-21T08:00:00+01:00'): void
    {
        $this->access = new StubAccess();
        $this->permissions = new NurseryPermissions();
        $this->applications = new FakeApplicationAccess();
        $this->audit = new RecordingAuditLog();
        $this->safety = new RecordingSafetyMessenger();
        $this->messenger = new RecordingMessenger();
        $this->world = new NurseryWorld();
        $this->periods = new FixedCurrentPeriod();
        $this->applications->set(self::TA, ApplicationAccess::ACTIVE);
        $this->applications->set(self::TB, ApplicationAccess::ACTIVE);

        $this->kernel = Kernel::boot(dirname(__DIR__, 3), Config::fromArray([
            'app' => ['name' => 'SCHOLARIS', 'debug' => false, 'timezone' => 'Africa/Douala', 'api_prefix' => '/api/v1', 'log_path' => 'storage/logs/test.log'],
            'database' => ['driver' => 'sqlite', 'database' => ':memory:'],
            'api' => ['cors_origins' => [], 'idempotent_paths' => [], 'rate_limits' => []],
        ]), $overrides + $this->world->bindings() + [
            Authenticator::class => new StubAuthenticator(),
            EstablishmentAccess::class => $this->access,
            PermissionChecker::class => $this->permissions,
            PermissionScopes::class => $this->permissions,
            ApplicationAccess::class => $this->applications,
            AuditLog::class => $this->audit,
            SafetyMessenger::class => $this->safety,
            Messenger::class => $this->messenger,
            NurseryClock::class => new NurseryClock($now),
            CurrentPeriod::class => $this->periods,
        ]);
        $this->kernel->container()->get(Migrator::class)->migrate();
        $this->seedEstablishment(self::TA, self::EA, 'Maternelle SYN-NUR A');
        $this->seedEstablishment(self::TB, self::EB, 'Maternelle SYN-NUR B');
        $this->grantAll(self::TA, self::DIRECTOR, self::EA);
    }

    protected function tearDown(): void
    {
        unset($this->kernel, $this->access, $this->permissions, $this->applications, $this->audit, $this->safety, $this->messenger, $this->world);
        gc_collect_cycles();
        parent::tearDown();
    }

    protected function seedEstablishment(string $tenantId, string $id, string $name, string $type = 'nursery'): void
    {
        $now = '2026-10-01T08:00:00+01:00';
        $this->service(ConnectionFactory::class)->pdo()->prepare(
            'INSERT INTO establishments (id, tenant_id, name, type, branding, modules_enabled, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute([$id, $tenantId, $name, $type, '{}', '[]', $now, $now]);
    }

    /** Toutes les permissions de la directrice (périmètre : tout l'établissement). */
    protected function grantAll(string $tenantId, string $userId, string $establishmentId): void
    {
        foreach (self::DIRECTOR_PERMISSIONS as $permission) {
            $this->grant($tenantId, $userId, $establishmentId, $permission);
        }
    }

    public const DIRECTOR_PERMISSIONS = [
        'nursery.settings.manage', 'nursery.section.manage', 'nursery.child.read', 'nursery.enrol', 'nursery.pickup.read', 'nursery.pickup.restrict',
        'nursery.pickup.manage', 'nursery.pickup.handover', 'nursery.register.read', 'nursery.dev.read', 'nursery.dev.validate', 'nursery.frame.manage',
        'nursery.lifebook.read', 'nursery.need.read', 'nursery.allergy.read', 'nursery.message.send', 'nursery.dashboard.read', 'attendance.take',
        'billing.manage',
    ];

    /** Donne une permission (tout l'établissement, ou une seule section) et l'accès à l'établissement. */
    protected function grant(string $tenantId, string $userId, string $establishmentId, string $permission, ?string $sectionId = null): void
    {
        $this->access->grant($userId, $establishmentId);
        $this->permissions->grant($tenantId, $userId, $permission, $establishmentId, $sectionId);
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array{0: int, 1: array<string, mixed>}
     */
    protected function call(string $method, string $path, ?array $body = null, string $userId = self::DIRECTOR, string $tenantId = self::TA, string $establishmentId = self::EA): array
    {
        $response = $this->raw($method, $path, $body, $userId, $tenantId, $establishmentId);
        $decoded = json_decode((string) $response->getBody(), true);

        return [$response->getStatusCode(), is_array($decoded) ? $decoded : []];
    }

    /** @param array<string, mixed>|null $body */
    protected function raw(string $method, string $path, ?array $body = null, string $userId = self::DIRECTOR, string $tenantId = self::TA, string $establishmentId = self::EA): ResponseInterface
    {
        $headers = ['Authorization' => 'Bearer ' . $userId . ':' . $tenantId, 'X-Establishment-Id' => $establishmentId];
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }

        return $this->kernel->handle(new ServerRequest($method, '/api/v1' . $path, $headers, $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR)));
    }

    /**
     * Appel qui doit réussir : rend la ressource (champ « data »).
     *
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    protected function ok(string $method, string $path, ?array $body = null, string $userId = self::DIRECTOR, string $tenantId = self::TA, string $establishmentId = self::EA): array
    {
        [$status, $json] = $this->call($method, $path, $body, $userId, $tenantId, $establishmentId);
        self::assertContains($status, [200, 201], $method . ' ' . $path . ' => ' . $status . ' ' . json_encode($json));

        return is_array($json['data'] ?? null) ? $json['data'] : $json;
    }

    /** Statut seul. @param array<string, mixed>|null $body */
    protected function code(string $method, string $path, ?array $body = null, string $userId = self::DIRECTOR, string $tenantId = self::TA, string $establishmentId = self::EA): int
    {
        return $this->call($method, $path, $body, $userId, $tenantId, $establishmentId)[0];
    }

    /** Paramètre `nursery.*` posé au niveau de l'établissement. */
    protected function setSetting(string $tenantId, string $establishmentId, string $key, mixed $value): void
    {
        $this->service(ConnectionFactory::class)->pdo()->prepare(
            "INSERT INTO settings (id, scope_type, tenant_id, establishment_id, setting_key, value_json, updated_by, created_at, updated_at) VALUES (?, 'establishment', ?, ?, ?, ?, 'test', '2026-10-21T08:00:00+01:00', '2026-10-21T08:00:00+01:00')",
        )->execute([(string) new Ulid(), $tenantId, $establishmentId, $key, json_encode($value, JSON_THROW_ON_ERROR)]);
    }

    /** Section (classe) créée dans le socle par StructureWriter. */
    protected function makeSection(string $tenantId, string $establishmentId, string $name, ?int $capacity = null): string
    {
        return $this->service(StructureWriter::class)->createStructure($tenantId, $establishmentId, new StructureDraft(StructureDraft::SCHOOL_CLASS, $name, null, null, $capacity));
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
