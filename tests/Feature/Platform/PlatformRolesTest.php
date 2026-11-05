<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use Scholaris\Modules\Socle\Identity\Application\PermissionResolver;
use Scholaris\Modules\Socle\Identity\Contracts\PlatformAdministrators;
use Scholaris\Modules\Socle\Identity\Contracts\PlatformStaff;
use Tests\Support\Platform\PlatformTestCase;

/**
 * C-112 point 5 : rôles de la plateforme (support, finance, audit). Les permissions sont lues dans role_permissions telles
 * que les migrations les sèment : chaque rôle reçoit 403 hors de son périmètre et 200 dans son périmètre.
 */
final class PlatformRolesTest extends PlatformTestCase
{
    private string $tenant;

    protected function setUp(): void
    {
        $this->bootPlatform();
        $this->tenant = $this->client('Institut du Littoral')['tenant'];
    }

    /** Crée un membre réel avec le rôle et lui accorde (doublure) exactement les permissions semées pour ce rôle. */
    private function member(string $role, string $login): string
    {
        $created = $this->kernel->container()->get(PlatformAdministrators::class)->create($login, 'Membre', ucfirst(str_replace('platform_', '', $role)));
        if ($role !== 'platform_admin') {
            $this->kernel->container()->get(PlatformStaff::class)->assign($created['user_id'], $role, 'root');
        }
        $statement = $this->connections()->pdo()->prepare('SELECT permission FROM role_permissions WHERE role = ?');
        $statement->execute([$role]);
        $this->permissions->grant($created['user_id'], 'platform', ...array_map('strval', $statement->fetchAll(\PDO::FETCH_COLUMN)));
        $this->secondFactor->allow($created['user_id']);

        return $created['user_id'];
    }

    /** @param array<string, mixed>|null $body */
    private function code(string $method, string $path, string $user, ?array $body = null): int
    {
        return $this->api($method, $path, $user, null, $body)[0]->getStatusCode();
    }

    public function testLeSupportVoitClientsUtilisateursJournauxMaisNiPrixNiReglagesNiPaiements(): void
    {
        $support = $this->member('platform_support', 'support@scholaris.test');
        $t = $this->tenant;

        self::assertSame(200, $this->code('GET', '/platform/clients', $support));
        self::assertSame(200, $this->code('GET', '/platform/clients/' . $t . '/users', $support));
        self::assertSame(200, $this->code('GET', '/platform/clients/' . $t . '/administrators', $support));
        self::assertSame(200, $this->code('GET', '/platform/audit', $support));
        self::assertSame(200, $this->code('GET', '/platform/access-requests', $support));
        self::assertSame(403, $this->code('GET', '/platform/payments', $support));
        self::assertSame(403, $this->code('GET', '/platform/settings', $support));
        self::assertSame(403, $this->code('PATCH', '/platform/settings', $support, ['access_code_ttl_minutes' => 60]));
        self::assertSame(403, $this->code('GET', '/platform/administrators', $support));
        self::assertSame(403, $this->code('POST', '/platform/clients/' . $t . '/applications', $support, ['app_key' => 'higher']));
        self::assertSame(403, $this->code('PATCH', '/platform/clients/' . $t . '/applications/higher', $support, ['monthly_price_xaf' => 1000]));
        self::assertSame(403, $this->code('POST', '/platform/clients/' . $t . '/regularisation/lock', $support, ['reason' => 'Test']));
        self::assertSame(403, $this->code('POST', '/platform/clients/' . $t . '/suspend', $support, ['reason' => 'Test']));

        [, $dashboard] = $this->api('GET', '/platform/dashboard', $support);
        self::assertTrue($dashboard['data']['clients']['available']);
        self::assertFalse($dashboard['data']['mrr_xaf']['available'], 'aucun indicateur financier pour le support');
        self::assertFalse($dashboard['data']['unpaid']['available']);

        [, $me] = $this->api('GET', '/platform/me', $support);
        self::assertSame('platform_support', $me['data']['role']);
        self::assertContains('platform.users.access_code', $me['data']['permissions']);
        self::assertNotContains('platform.pricing.manage', $me['data']['permissions']);
    }

    public function testLaFinanceGerePaiementsEtPrixSansVoirLesUtilisateurs(): void
    {
        $finance = $this->member('platform_finance', 'finance@scholaris.test');
        $t = $this->tenant;

        self::assertSame(200, $this->code('GET', '/platform/payments', $finance));
        self::assertSame(200, $this->code('GET', '/platform/regularisation', $finance));
        self::assertSame(200, $this->code('GET', '/platform/regularisation/declarations', $finance));
        self::assertSame(200, $this->code('GET', '/platform/clients', $finance));
        self::assertSame(403, $this->code('GET', '/platform/clients/' . $t . '/users', $finance));
        self::assertSame(403, $this->code('GET', '/platform/clients/' . $t . '/administrators', $finance));
        self::assertSame(403, $this->code('GET', '/platform/administrators', $finance));
        self::assertSame(403, $this->code('GET', '/platform/audit', $finance));
        self::assertSame(403, $this->code('GET', '/platform/settings', $finance));
        self::assertSame(403, $this->code('GET', '/platform/access-requests', $finance));
        self::assertSame(403, $this->code('POST', '/platform/clients/' . $t . '/users/x/access-code', $finance, []));

        [, $dashboard] = $this->api('GET', '/platform/dashboard', $finance);
        self::assertTrue($dashboard['data']['mrr_xaf']['available']);
    }

    public function testLAuditeurLitToutMaisNAgitJamais(): void
    {
        $auditor = $this->member('platform_auditor', 'audit@scholaris.test');
        $t = $this->tenant;

        foreach (['/platform/clients', '/platform/clients/' . $t . '/users', '/platform/payments', '/platform/settings', '/platform/settings/contact', '/platform/audit', '/platform/administrators', '/platform/access-requests', '/platform/applications', '/platform/regularisation'] as $path) {
            self::assertSame(200, $this->code('GET', $path, $auditor), $path);
        }
        self::assertSame(403, $this->code('PATCH', '/platform/settings', $auditor, ['access_code_ttl_minutes' => 60]));
        self::assertSame(403, $this->code('POST', '/platform/payments', $auditor, ['tenant_id' => $t, 'app_key' => 'higher', 'months' => 1, 'channel' => 'cash']));
        self::assertSame(403, $this->code('POST', '/platform/clients/' . $t . '/users/x/access-code', $auditor, []));
        self::assertSame(403, $this->code('POST', '/platform/clients/' . $t . '/regularisation/lock', $auditor, ['reason' => 'Test']));
        self::assertSame(403, $this->code('POST', '/platform/administrators', $auditor, ['first_name' => 'Jean', 'last_name' => 'Kamga', 'email' => 'jean@scholaris.test']));
        self::assertSame(403, $this->code('PATCH', '/platform/applications/higher', $auditor, ['label' => 'X']));
    }

    public function testUnCompteDUnClientNOuvreJamaisLaPlateformeMemeAvecLesPermissions(): void
    {
        $this->permissions->grant('boss', $this->tenant, 'platform.access', 'platform.audit.read');
        self::assertSame(403, $this->api('GET', '/platform/audit', 'boss', $this->tenant)[0]->getStatusCode());
        self::assertSame(403, $this->api('GET', '/platform/me', 'boss', $this->tenant)[0]->getStatusCode());
    }

    public function testAttributionDuRoleDansLEcranAdministrateurs(): void
    {
        $admin = $this->member('platform_admin', 'chef@scholaris.test');

        [$invited, $json] = $this->api('POST', '/platform/administrators', $admin, null, ['first_name' => 'Awa', 'last_name' => 'Ngo', 'email' => 'awa@scholaris.test', 'role' => 'platform_support']);
        self::assertSame(201, $invited->getStatusCode(), (string) $invited->getBody());
        self::assertSame('platform_support', $json['data']['role']);
        $awa = (string) $json['data']['id'];
        [, $list] = $this->api('GET', '/platform/administrators', $admin);
        $roles = array_column($list['data'], 'role');
        sort($roles);
        self::assertSame(['platform_admin', 'platform_support'], $roles);

        [$changed, $json] = $this->api('PATCH', '/platform/administrators/' . $awa . '/role', $admin, null, ['role' => 'platform_finance']);
        self::assertSame(200, $changed->getStatusCode(), (string) $changed->getBody());
        self::assertSame('platform_finance', $json['data']['role']);
        self::assertSame('platform_finance', $this->kernel->container()->get(PlatformStaff::class)->roleOf($awa));
        self::assertSame(422, $this->api('PATCH', '/platform/administrators/' . $awa . '/role', $admin, null, ['role' => 'super_admin'])[0]->getStatusCode());
        self::assertSame(409, $this->api('PATCH', '/platform/administrators/' . $admin . '/role', $admin, null, ['role' => 'platform_auditor'])[0]->getStatusCode(), 'jamais son propre rôle');
        // Seul administrateur actif : un autre acteur ne peut pas le rétrograder.
        self::assertSame(409, $this->api('PATCH', '/platform/administrators/' . $admin . '/role', self::ROOT, null, ['role' => 'platform_auditor'])[0]->getStatusCode());

        $this->secondFactor->revoke($admin);
        self::assertSame(403, $this->api('PATCH', '/platform/administrators/' . $awa . '/role', $admin, null, ['role' => 'platform_auditor'])[0]->getStatusCode(), '2FA récente');

        $count = $this->connections()->pdo()->prepare('SELECT COUNT(*) FROM audit_log WHERE tenant_id = ? AND action = ?');
        $count->execute(['platform', 'platform.administrator_role_changed']);
        $logged = $count->fetchColumn();
        self::assertSame(1, (int) $logged);
        $statement = $this->connections()->pdo()->prepare('SELECT person_id FROM users WHERE id = ?');
        $statement->execute([$awa]);
        self::assertTrue($this->kernel->container()->get(PermissionResolver::class)->isPlatformAdmin('platform', (string) $statement->fetchColumn()), 'membre de la plateforme : 2FA toujours exigée');
    }
}
