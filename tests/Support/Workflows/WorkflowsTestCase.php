<?php

declare(strict_types=1);

namespace Tests\Support\Workflows;

use Nyholm\Psr7\ServerRequest;
use Scholaris\Core\Security\PermissionChecker;
use Tests\Support\Higher\Academic\AcademicTestCase;

/**
 * Base des tests des circuits de validation : IPES A (administrateur ADMIN_WF avec `workflows.manage`) et IPES B, base migrée,
 * double authentification récente de l'administrateur à la demande, compte de plateforme (padmin) avec ses permissions.
 */
abstract class WorkflowsTestCase extends AcademicTestCase
{
    public const WF_ADMIN = 'wf-admin';
    public const WF_ADMIN_B = 'wf-admin-b';
    public const PLATFORM_ADMIN = 'padmin';
    public const PLATFORM_PERMISSIONS = ['platform.access', 'platform.clients.read', 'platform.clients.manage', 'platform.settings.manage'];

    protected function bootWorkflows(): void
    {
        $this->bootAcademic();
        $this->kernel->container()->set(PermissionChecker::class, new PlatformGrants($this->permissions, self::PLATFORM_PERMISSIONS));
        $this->assign(self::T, self::WF_ADMIN, self::E, 'workflows.manage', 'establishment');
        $this->assign(self::TB, self::WF_ADMIN_B, self::EB, 'workflows.manage', 'establishment');
        $this->seedClient(self::T);
        $this->seedClient(self::TB);
    }

    private function seedClient(string $tenantId): void
    {
        $now = '2026-10-01T08:00:00+01:00';
        $pdo = $this->service(\Scholaris\Core\Database\ConnectionFactory::class)->pdo();
        $exists = $pdo->prepare('SELECT COUNT(*) FROM tenants WHERE id = ?');
        $exists->execute([$tenantId]);
        if ((int) $exists->fetchColumn() === 0) {
            $pdo->prepare('INSERT INTO tenants (id, name, status, plan, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)')->execute([$tenantId, 'Client ' . $tenantId, 'active', 'standard', $now, $now]);
        }
    }

    /** @param array<string, mixed>|null $body @return array{0: int, 1: array<string, mixed>} */
    protected function platform(string $method, string $path, ?array $body = null, string $user = self::PLATFORM_ADMIN): array
    {
        $headers = ['Authorization' => 'Bearer ' . $user . ':platform'];
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        $response = $this->kernel->handle(new ServerRequest($method, '/api/v1' . $path, $headers, $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR)));
        $decoded = json_decode((string) $response->getBody(), true);

        return [$response->getStatusCode(), is_array($decoded) ? $decoded : []];
    }

    /** L'administrateur du client A a confirmé son identité récemment. */
    protected function confirm(string $tenant = self::T, string $user = self::WF_ADMIN): void
    {
        $this->secondFactor->validate($tenant, $user);
    }
}
