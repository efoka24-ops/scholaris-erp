<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Tests\Support\Platform\PlatformTestCase;

/** C-112 point 3 : journal d'activité transversal, export CSV, fiche utilisateur et déconnexion forcée. */
final class PlatformActivityTest extends PlatformTestCase
{
    private string $tenant;
    private string $other;
    private const USER = '01USERLITTORAL0000000000A1';

    protected function setUp(): void
    {
        $this->bootPlatform();
        $this->clock->set(date(DATE_ATOM));
        $this->tenant = $this->client('Institut du Littoral')['tenant'];
        $this->other = $this->client('Lycée du Nord', 'secondary')['tenant'];
        $now = date(DATE_ATOM);
        $pdo = $this->connections()->pdo();
        $pdo->prepare('INSERT INTO people (id, tenant_id, first_name, last_name, phone, email, locale, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute(['P1', $this->tenant, 'Awa', 'Ngo', '+237690001234', 'awa@client.test', 'fr', $now]);
        $pdo->prepare('INSERT INTO users (id, tenant_id, person_id, phone, email, locale, status, last_login_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([self::USER, $this->tenant, 'P1', '+237690001234', 'awa@client.test', 'fr', 'active', $now, $now, $now]);
        foreach (['T1', 'T2'] as $token) {
            $pdo->prepare('INSERT INTO access_tokens (id, tenant_id, user_id, device_id, token_hash, expires_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$token, $this->tenant, self::USER, 'D1', hash('sha256', $token), date(DATE_ATOM, time() + 3600), $now]);
        }
        $audit = $this->kernel->container()->get(AuditLog::class);
        $audit->append($this->tenant, self::USER, 'identity.login', 'user', self::USER, null, ['method' => 'password'], '198.51.100.20');
        $audit->append($this->tenant, self::USER, 'billing.invoice_issued', 'invoice', 'F1');
        $audit->append($this->other, 'U9', 'identity.login', 'user', 'U9');
        $this->permissions->grant(self::ROOT, 'platform', 'platform.users.read', 'platform.users.logout', 'platform.audit.export');
    }

    public function testJournalTransversalFiltreEtPagine(): void
    {
        [$response, $all] = $this->api('GET', '/platform/activity?filter[action]=identity.login', self::ROOT);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertEqualsCanonicalizing([$this->tenant, $this->other], array_column($all['data'], 'tenant_id'));
        self::assertContains('Institut du Littoral', array_column($all['data'], 'tenant_name'));

        [, $one] = $this->api('GET', '/platform/activity?filter[tenant_id]=' . $this->tenant . '&filter[actor_id]=' . self::USER . '&per_page=1', self::ROOT);
        self::assertCount(1, $one['data']);
        self::assertSame('billing.invoice_issued', $one['data'][0]['action']);
        [, $next] = $this->api('GET', '/platform/activity?filter[tenant_id]=' . $this->tenant . '&filter[actor_id]=' . self::USER . '&per_page=1&cursor=' . $one['meta']['next_cursor'], self::ROOT);
        self::assertSame('identity.login', $next['data'][0]['action']);
        self::assertSame(422, $this->api('GET', '/platform/activity?filter[from]=hier', self::ROOT)[0]->getStatusCode());

        [$csv] = $this->api('GET', '/platform/activity/export?filter[tenant_id]=' . $this->tenant, self::ROOT);
        self::assertSame(200, $csv->getStatusCode());
        self::assertStringContainsString('attachment', $csv->getHeaderLine('Content-Disposition'));
        self::assertStringContainsString('billing.invoice_issued', (string) $csv->getBody());

        $this->permissions->grant('support', 'platform', 'platform.access', 'platform.audit.read');
        self::assertSame(200, $this->api('GET', '/platform/activity', 'support')[0]->getStatusCode());
        self::assertSame(403, $this->api('GET', '/platform/activity/export', 'support')[0]->getStatusCode(), 'export réservé à l’administrateur et à l’audit');
    }

    public function testFicheUtilisateurMasqueeEtDeconnexionForcee(): void
    {
        [$response, $card] = $this->api('GET', '/platform/clients/' . $this->tenant . '/users/' . self::USER, self::ROOT);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('6•• •• •• 34', $card['data']['phone']);
        self::assertSame('a•••@client.test', $card['data']['email']);
        self::assertCount(2, $card['data']['sessions']);
        self::assertSame('password', $card['data']['logins'][0]['method']);
        self::assertCount(2, $card['data']['actions']);
        self::assertSame(404, $this->api('GET', '/platform/clients/' . $this->other . '/users/' . self::USER, self::ROOT)[0]->getStatusCode(), 'un compte d’un autre client');

        $this->secondFactor->revoke(self::ROOT);
        self::assertSame(403, $this->api('POST', '/platform/clients/' . $this->tenant . '/users/' . self::USER . '/logout', self::ROOT, null, [])[0]->getStatusCode());
        $this->secondFactor->allow(self::ROOT);
        [$done, $json] = $this->api('POST', '/platform/clients/' . $this->tenant . '/users/' . self::USER . '/logout', self::ROOT, null, []);
        self::assertSame(200, $done->getStatusCode(), (string) $done->getBody());
        self::assertSame(2, $json['data']['revoked_sessions']);
        self::assertSame([], $this->api('GET', '/platform/clients/' . $this->tenant . '/users/' . self::USER, self::ROOT)[1]['data']['sessions']);
        $statement = $this->connections()->pdo()->prepare('SELECT COUNT(*) FROM audit_log WHERE tenant_id = ? AND action = ?');
        $statement->execute([$this->tenant, 'platform.user_sessions_revoked']);
        self::assertSame(1, (int) $statement->fetchColumn(), 'journalisé aussi chez le client');

        $this->permissions->grant('finance', 'platform', 'platform.access', 'platform.clients.read');
        self::assertSame(403, $this->api('GET', '/platform/clients/' . $this->tenant . '/users/' . self::USER, 'finance')[0]->getStatusCode());
    }
}
