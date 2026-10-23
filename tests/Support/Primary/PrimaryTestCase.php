<?php

declare(strict_types=1);

namespace Tests\Support\Primary;

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
use Scholaris\Modules\Apps\Primary\Support\PrimaryClock;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Identity\Contracts\PermissionScopes;
use Scholaris\Modules\Socle\Identity\Contracts\Scope;
use Scholaris\Modules\Socle\Organisation\Contracts\StructureDraft;
use Scholaris\Modules\Socle\Organisation\Contracts\StructureWriter;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;
use Tests\Support\StubAccess;
use Tests\Support\StubAuthenticator;

/**
 * Base des tests de l'application primaire : Kernel de test avec les vraies routes Primary, base SQLite migrée, arbre RÉEL de
 * l'établissement (niveaux et classes créés par StructureWriter), et doublures déterministes pour l'authentification
 * (« Bearer utilisateur:client »), les permissions, les périmètres, le droit d'usage et l'audit. Données fictives.
 *
 * Deux écoles privées : client A (école A, établissement E_A) et client B (école B, établissement E_B).
 */
abstract class PrimaryTestCase extends TestCase
{
    public const TA = 'ecole-a';
    public const EA = 'e-ecole-a';
    public const TB = 'ecole-b';
    public const EB = 'e-ecole-b';
    public const DIRECTOR = 'directeur-a';
    public const YEAR = 'AY-2026';

    protected Kernel $kernel;
    protected StubAccess $access;
    protected PrimaryPermissions $permissions;
    protected PrimaryScopes $scopes;
    protected FakeApplicationAccess $applications;
    protected RecordingAuditLog $audit;

    /** @param array<string, mixed> $overrides */
    protected function bootPrimary(array $overrides = [], string $today = '2026-10-21'): void
    {
        $this->access = new StubAccess();
        $this->permissions = new PrimaryPermissions();
        $this->scopes = new PrimaryScopes();
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
            PrimaryClock::class => new PrimaryClock($today),
        ]);
        $this->kernel->container()->get(Migrator::class)->migrate();
        $this->seedEstablishment(self::TA, self::EA, 'École A');
        $this->seedEstablishment(self::TB, self::EB, 'École B');
        $this->grantAll(self::TA, self::DIRECTOR, self::EA);
    }

    protected function tearDown(): void
    {
        unset($this->kernel, $this->access, $this->permissions, $this->scopes, $this->applications, $this->audit);
        gc_collect_cycles();
        parent::tearDown();
    }

    protected function seedEstablishment(string $tenantId, string $id, string $name): void
    {
        $now = '2026-10-01T08:00:00+01:00';
        $this->service(ConnectionFactory::class)->pdo()->prepare(
            'INSERT INTO establishments (id, tenant_id, name, type, branding, modules_enabled, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute([$id, $tenantId, $name, 'primary', '{}', '[]', $now, $now]);
    }

    /** Toutes les permissions primary.* sur tout l'établissement pour l'utilisateur. */
    protected function grantAll(string $tenantId, string $userId, string $establishmentId): void
    {
        foreach (self::ALL_PERMISSIONS as $permission) {
            $this->assign($tenantId, $userId, $establishmentId, $permission, Scope::ESTABLISHMENT, $establishmentId);
        }
    }

    public const ALL_PERMISSIONS = [
        'primary.referential.read', 'primary.referential.manage', 'primary.settings.manage', 'primary.learner.read', 'primary.enrol',
        'primary.competency.read', 'primary.competency.enter', 'primary.competency.validate', 'primary.conduct.read', 'primary.conduct.enter',
        'primary.journal.read', 'primary.journal.write', 'primary.dashboard.read', 'documents.issue', 'documents.read',
        'attendance.take', 'attendance.read', 'assessment.correction.approve',
    ];

    /** Affecte une permission avec un périmètre et donne l'accès à l'établissement. */
    protected function assign(string $tenantId, string $userId, string $establishmentId, string $permission, string $scopeType, ?string $scopeId = null, string $role = 'test_role'): void
    {
        $this->access->grant($userId, $establishmentId);
        $this->permissions->grant($tenantId, $userId, $permission, $establishmentId);
        $this->scopes->add($tenantId, $userId, $establishmentId, $permission, new Scope($scopeType, $scopeId ?? $establishmentId, $role));
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

    /** Niveau et classes créés dans le socle par StructureWriter.
     *
     * @return array{level: string, classes: list<string>}
     */
    protected function makeLevel(string $tenantId, string $establishmentId, string $name, int $classes = 1): array
    {
        $writer = $this->service(StructureWriter::class);
        $level = $writer->createStructure($tenantId, $establishmentId, new StructureDraft(StructureDraft::LEVEL, $name));
        $ids = [];
        for ($i = 1; $i <= $classes; ++$i) {
            $ids[] = $writer->createStructure($tenantId, $establishmentId, new StructureDraft(StructureDraft::SCHOOL_CLASS, $name . ' ' . chr(64 + $i), null, $level));
        }

        return ['level' => $level, 'classes' => $ids];
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
