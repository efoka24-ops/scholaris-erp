<?php

declare(strict_types=1);

namespace Tests\Support\Higher;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Scholaris\Core\Config\Config;
use Scholaris\Core\Database\Migrator;
use Scholaris\Core\Kernel;
use Scholaris\Core\Security\Authenticator;
use Scholaris\Core\Security\EstablishmentAccess;
use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Identity\Contracts\PermissionScopes;
use Scholaris\Modules\Socle\Identity\Contracts\Scope;
use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitTree;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;
use Tests\Fixtures\Higher\HigherOrgFixture;
use Tests\Support\StubAccess;
use Tests\Support\StubAuthenticator;

/**
 * Base des tests de l'application Supérieur : Kernel de test avec les vraies routes Higher et des doublures déterministes pour
 * l'authentification (« Bearer utilisateur:client »), l'accès aux établissements, les permissions, les périmètres (S15), l'arbre de
 * l'établissement, le droit d'usage (Platform) et le journal d'audit. Organisation de deux IPES : Tests\Fixtures\Higher\HigherOrgFixture.
 *
 * Les sous-modules étendent cette classe ; `migrate: true` migre une base SQLite en mémoire (tables hi_* comprises).
 */
abstract class HigherTestCase extends TestCase
{
    protected Kernel $kernel;
    protected StubAccess $access;
    protected HigherPermissions $permissions;
    protected FakePermissionScopes $scopes;
    protected InMemoryOrgUnitTree $tree;
    protected FakeApplicationAccess $applications;
    protected RecordingAuditLog $audit;

    /** @param array<string, mixed> $overrides */
    protected function bootHigher(array $overrides = [], bool $migrate = false): void
    {
        $this->access = new StubAccess();
        $this->permissions = new HigherPermissions();
        $this->scopes = new FakePermissionScopes();
        $this->tree = new InMemoryOrgUnitTree();
        $this->applications = new FakeApplicationAccess();
        $this->audit = new RecordingAuditLog();
        HigherOrgFixture::seed($this->tree);
        $this->applications->set(HigherOrgFixture::TENANT_A, ApplicationAccess::ACTIVE);
        $this->applications->set(HigherOrgFixture::TENANT_B, ApplicationAccess::ACTIVE);

        $this->kernel = Kernel::boot(dirname(__DIR__, 3), Config::fromArray([
            'app' => ['name' => 'SCHOLARIS', 'debug' => false, 'timezone' => 'Africa/Douala', 'api_prefix' => '/api/v1', 'log_path' => 'storage/logs/test.log'],
            'database' => ['driver' => 'sqlite', 'database' => ':memory:'],
            'api' => ['cors_origins' => [], 'idempotent_paths' => [], 'rate_limits' => []],
        ]), $overrides + [
            Authenticator::class => new StubAuthenticator(),
            EstablishmentAccess::class => $this->access,
            PermissionChecker::class => $this->permissions,
            PermissionScopes::class => $this->scopes,
            OrgUnitTree::class => $this->tree,
            ApplicationAccess::class => $this->applications,
            AuditLog::class => $this->audit,
        ]);
        if ($migrate) {
            $this->kernel->container()->get(Migrator::class)->migrate();
        }
    }

    /** PHPUnit garde chaque test terminé en mémoire jusqu'à la fin de la suite : on libère le noyau et les doublures. */
    protected function tearDown(): void
    {
        unset($this->kernel, $this->access, $this->permissions, $this->scopes, $this->tree, $this->applications, $this->audit);
        gc_collect_cycles();
        parent::tearDown();
    }

    /**
     * Affecte une permission avec un périmètre (rôle + périmètre, RG-ORG-2) et donne l'accès à l'établissement.
     *
     * @param string $scopeType establishment, org_unit, structure ou class
     */
    protected function assign(string $tenantId, string $userId, string $establishmentId, string $permission, string $scopeType, ?string $scopeId = null, string $role = 'test_role'): void
    {
        $this->access->grant($userId, $establishmentId);
        $this->permissions->grant($tenantId, $userId, $permission, $establishmentId);
        $this->scopes->add($tenantId, $userId, $establishmentId, $permission, new Scope($scopeType, $scopeId ?? $establishmentId, $role));
    }

    protected function principal(string $userId, string $tenantId = HigherOrgFixture::TENANT_A): Principal
    {
        return new Principal($userId, $tenantId);
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array{0: ResponseInterface, 1: array<string, mixed>}
     */
    protected function api(string $method, string $path, string $userId, string $tenantId, string $establishmentId, ?array $body = null): array
    {
        $headers = ['Authorization' => 'Bearer ' . $userId . ':' . $tenantId, 'X-Establishment-Id' => $establishmentId];
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        $response = $this->kernel->handle(new ServerRequest($method, '/api/v1' . $path, $headers, $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR)));
        $decoded = json_decode((string) $response->getBody(), true);

        return [$response, is_array($decoded) ? $decoded : []];
    }

    /** @template T of object @param class-string<T> $class @return T */
    protected function service(string $class): object
    {
        return $this->kernel->container()->get($class);
    }
}
