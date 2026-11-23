<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Tests\Support\Platform\PlatformTestCase;

/** C-112 point 2 : indicateurs d'usage du tableau de bord de la plateforme. */
final class PlatformKpiTest extends PlatformTestCase
{
    private int $now;

    protected function setUp(): void
    {
        $this->bootPlatform();
        // Le journal d'audit horodate à l'heure réelle : l'horloge de test s'y aligne.
        $this->clock->set(date(DATE_ATOM));
        $this->now = $this->clock->now();
    }

    private function iso(int $secondsAgo): string
    {
        return (new \DateTimeImmutable('@' . ($this->now - $secondsAgo)))->setTimezone(new \DateTimeZone('Africa/Douala'))->format(DATE_ATOM);
    }

    private function user(string $tenant, string $id, string $first, ?int $lastLoginAgo, string $status = 'active'): void
    {
        $pdo = $this->connections()->pdo();
        $pdo->prepare('INSERT INTO people (id, tenant_id, first_name, last_name, phone, email, locale, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute(['P' . $id, $tenant, $first, 'Test', '+237690000' . substr($id, -3), strtolower($first) . '@client.test', 'fr', $this->iso(86400 * 60)]);
        $pdo->prepare('INSERT INTO users (id, tenant_id, person_id, phone, email, locale, status, last_login_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$id, $tenant, 'P' . $id, '+237690000' . substr($id, -3), strtolower($first) . '@client.test', 'fr', $status, $lastLoginAgo === null ? null : $this->iso($lastLoginAgo), $this->iso(86400 * 60), $this->iso(86400 * 60)]);
    }

    private function session(string $tenant, string $user, int $lastUsedAgo): void
    {
        $this->connections()->pdo()->prepare('INSERT INTO devices (id, tenant_id, user_id, fingerprint, name, last_seen_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute(['D' . $user, $tenant, $user, str_repeat('a', 64), 'Chrome sur Android', $this->iso($lastUsedAgo), $this->iso(3600)]);
        $this->connections()->pdo()->prepare('INSERT INTO access_tokens (id, tenant_id, user_id, device_id, token_hash, expires_at, last_used_at, active_role, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute(['T' . $user, $tenant, $user, 'D' . $user, hash('sha256', $user), $this->iso(-3600), $this->iso($lastUsedAgo), 'directeur', $this->iso(3600)]);
    }

    public function testIndicateursDUsage(): void
    {
        $littoral = $this->client('Institut du Littoral')['tenant'];
        $nord = $this->client('Lycée du Nord', 'secondary')['tenant'];
        $this->api('POST', '/platform/clients/' . $littoral . '/applications', self::ROOT, null, ['app_key' => 'higher']);
        $this->user($littoral, '01USERLITTORAL0000000000A1', 'Awa', 3600);
        $this->user($littoral, '01USERLITTORAL0000000000A2', 'Paul', 86400 * 10, 'locked');
        $this->user($nord, '01USERNORD000000000000000B1', 'Jean', 86400 * 20);
        $this->session($littoral, '01USERLITTORAL0000000000A1', 300);
        $this->session($nord, '01USERNORD000000000000000B1', 3600);
        $audit = $this->kernel->container()->get(AuditLog::class);
        $audit->append($littoral, '01USERLITTORAL0000000000A1', 'identity.login', 'user', '01USERLITTORAL0000000000A1');
        $audit->append($littoral, '01USERLITTORAL0000000000A1', 'identity.login', 'user', '01USERLITTORAL0000000000A1');
        $audit->append($littoral, '01USERLITTORAL0000000000A2', 'identity.login_locked', 'user', '01USERLITTORAL0000000000A2');

        $this->permissions->grant(self::ROOT, 'platform', 'platform.users.read');
        [$response, $json] = $this->api('GET', '/platform/kpi', self::ROOT);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $kpi = $json['data'];

        self::assertSame(1, $kpi['online']['total'], 'sessions utilisées depuis 15 minutes');
        self::assertSame('Awa', $kpi['online']['items'][0]['first_name']);
        self::assertSame('Institut du Littoral', $kpi['online']['items'][0]['tenant_name']);
        self::assertSame('Chrome sur Android', $kpi['online']['items'][0]['device']);
        self::assertSame(2, $kpi['logins']['today']);
        self::assertCount(30, $kpi['logins']['series']);
        self::assertSame(1, $kpi['active_users']['d7']);
        self::assertSame(3, $kpi['active_users']['d30']);
        self::assertSame(1, $kpi['security']['locked_accounts']);
        self::assertSame(1, $kpi['security']['lockouts_24h']);
        self::assertSame(1, $kpi['clients_activity']['active'], 'Lycée du Nord : aucune connexion depuis 14 jours');
        self::assertSame(1, $kpi['clients_activity']['inactive']);
        self::assertSame('Institut du Littoral', $kpi['clients_activity']['top_active'][0]['name']);
        $higher = array_column($kpi['usage_by_app'], null, 'key')['higher'];
        self::assertSame(1, $higher['clients']);
        self::assertSame(1, $higher['establishments']);
        self::assertSame(['trials' => 1, 'converted' => 0, 'rate_percent' => 0], $kpi['conversion']);
        self::assertSame(['sent' => 0, 'delivered' => 0, 'failed' => 0], $kpi['messages']['today']['sms']);

        // Réglage : client inactif au-delà de 30 jours.
        $this->api('PATCH', '/platform/settings', self::ROOT, null, ['kpi_inactive_days' => 30]);
        self::assertSame(2, $this->api('GET', '/platform/kpi', self::ROOT)[1]['data']['clients_activity']['active']);
    }

    public function testLaFinanceVoitLesComptesMaisAucunNom(): void
    {
        $littoral = $this->client('Institut du Littoral')['tenant'];
        $this->user($littoral, '01USERLITTORAL0000000000A1', 'Awa', 3600);
        $this->session($littoral, '01USERLITTORAL0000000000A1', 60);
        $this->permissions->grant('finance', 'platform', 'platform.access', 'platform.dashboard.read', 'platform.kpi.finance');
        [, $json] = $this->api('GET', '/platform/kpi', 'finance');
        self::assertSame(1, $json['data']['online']['total']);
        self::assertSame([], $json['data']['online']['items']);
        self::assertSame(403, $this->api('GET', '/platform/kpi', 'boss', $littoral)[0]->getStatusCode());
    }
}
