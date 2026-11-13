<?php

declare(strict_types=1);

namespace Tests\Isolation;

use Tests\Support\Platform\PlatformTestCase;

/**
 * Séparation plateforme / client et isolation entre clients pour le module Platform :
 * l'administrateur de plateforme n'atteint aucune donnée métier d'un client ; un client n'atteint pas l'espace /platform
 * ni les abonnements d'un autre client.
 */
final class PlatformIsolationTest extends PlatformTestCase
{
    /** @var array{tenant: string, establishment: string} */
    private array $a;
    /** @var array{tenant: string, establishment: string} */
    private array $b;

    protected function setUp(): void
    {
        $this->bootPlatform();
        $this->a = $this->client('Institut A');
        $this->b = $this->client('Institut B');
        foreach ([$this->a, $this->b] as $client) {
            $this->api('POST', '/applications/higher/open', 'boss', $client['tenant'], null, $client['establishment']);
        }
    }

    /** @return list<array{0: string, 1: string}> */
    private function platformRoutes(): array
    {
        $routes = [];
        foreach ($this->kernel->routes()->all() as $route) {
            if (str_starts_with($route['path'], '/api/v1/platform/') && ($route['options']['auth'] ?? true) === true) {
                $routes[] = [$route['method'], substr((string) preg_replace('/\{[^}]+\}/', 'x', $route['path']), strlen('/api/v1'))];
            }
        }

        return $routes;
    }

    public function testLesRoutesDeLaPlateformeRefusentLesAnonymesEtLesUtilisateursDesClients(): void
    {
        $routes = $this->platformRoutes();
        self::assertGreaterThan(15, count($routes));
        // Même avec toutes les permissions platform.* accordées dans son client, un utilisateur d'un client reste refusé.
        $this->permissions->grant('boss', $this->a['tenant'], 'platform.access', 'platform.dashboard.read', 'platform.clients.read', 'platform.requests.decide', 'platform.applications.manage');

        foreach ($routes as [$method, $path]) {
            self::assertSame(401, $this->api($method, $path, null, null, $method === 'GET' ? null : [])[0]->getStatusCode(), "$method $path anonyme");
            self::assertSame(403, $this->api($method, $path, 'boss', $this->a['tenant'], $method === 'GET' ? null : [])[0]->getStatusCode(), "$method $path par un client");
        }
    }

    public function testUnCompteDeLaPlateformeSansLeRoleEstRefuse(): void
    {
        $this->permissions->grant('intrus', 'platform', 'platform.dashboard.read');

        self::assertSame(403, $this->api('GET', '/platform/dashboard', 'intrus')[0]->getStatusCode(), 'platform.access est exigé en plus de la permission de l\'action.');
        self::assertSame(200, $this->api('GET', '/platform/dashboard', self::ROOT)[0]->getStatusCode());
    }

    public function testLesActionsSensiblesExigentUneDoubleAuthentificationRecente(): void
    {
        $this->secondFactor->revoke(self::ROOT);
        $request = $this->submitRequest();

        [$response, $json] = $this->api('POST', '/platform/access-requests/' . $request['id'] . '/accept', self::ROOT, null, []);
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('https://api.scholaris.cm/errors/two-factor-recent-required', $json['type']);
        self::assertSame(403, $this->api('POST', '/platform/clients/' . $this->a['tenant'] . '/applications/higher/suspend', self::ROOT, null, ['reason' => 'x'])[0]->getStatusCode());
        self::assertSame(403, $this->api('PATCH', '/platform/applications/higher', self::ROOT, null, ['monthly_price_xaf' => 1])[0]->getStatusCode());
        self::assertSame(200, $this->api('GET', '/platform/clients', self::ROOT)[0]->getStatusCode(), 'La consultation reste possible.');

        $this->secondFactor->allow(self::ROOT);
        self::assertSame(200, $this->api('POST', '/platform/access-requests/' . $request['id'] . '/accept', self::ROOT, null, [])[0]->getStatusCode());
    }

    public function testLAdministrateurDePlateformeNAccedePasAuxDonneesMetierDUnClient(): void
    {
        $this->permissions->grant(self::ROOT, 'platform', 'billing.read', 'people.read', 'applications.manage');
        $business = [
            ['GET', '/fee-items'], ['GET', '/invoices'], ['GET', '/payments'], ['GET', '/students'], ['GET', '/people'],
            ['POST', '/applications/higher/open'], ['GET', '/applications/higher/payments'],
        ];
        foreach ($business as [$method, $path]) {
            $status = $this->api($method, $path, self::ROOT, 'platform', $method === 'GET' ? null : [], $this->a['establishment'])[0]->getStatusCode();
            self::assertContains($status, [403, 404], "$method $path par l'administrateur de plateforme");
        }
        self::assertSame(403, $this->api('GET', '/me/applications', self::ROOT)[0]->getStatusCode());

        $detail = $this->api('GET', '/platform/clients/' . $this->a['tenant'], self::ROOT)[1]['data'];
        self::assertSame(['id', 'name', 'status', 'state', 'state_reason', 'suspended_at', 'archived_at', 'contact', 'city', 'region', 'locale', 'access_request_id', 'created_at', 'establishments', 'applications'], array_keys($detail), 'Identité, contact, état, établissements et abonnements seulement (aucune donnée métier).');
        self::assertSame(['id', 'name', 'type', 'establishment_subtype', 'closed_at', 'created_at'], array_keys($detail['establishments'][0]));
    }

    public function testUnClientNeVoitNiNePaieLesAbonnementsDUnAutre(): void
    {
        $this->api('POST', '/applications/higher/payments', 'boss', $this->b['tenant'], ['months' => 1, 'channel' => 'mtn_momo', 'payer_phone' => '+237670000000'], $this->b['establishment']);
        $provider = (string) $this->connections()->pdo()->query('SELECT provider_reference FROM platform_payments')->fetchColumn();
        $this->webhook('evt-b', 'payment.succeeded', 60000, $provider);
        $paymentB = (string) $this->connections()->pdo()->query('SELECT id FROM platform_payments')->fetchColumn();

        self::assertSame(404, $this->api('GET', '/application-payments/' . $paymentB . '/receipt', 'boss', $this->a['tenant'], null, $this->a['establishment'])[0]->getStatusCode());
        self::assertSame([], $this->api('GET', '/applications/higher/payments', 'boss', $this->a['tenant'], null, $this->a['establishment'])[1]['data']);
        // Même en désignant l'établissement de B, le client A ne lit que ses propres abonnements (filtre par client imposé).
        self::assertSame([], $this->api('GET', '/applications/higher/payments', 'boss', $this->a['tenant'], null, $this->b['establishment'])[1]['data'] ?? []);

        $mine = $this->api('GET', '/me/applications', 'teacher', $this->a['tenant'])[1]['data'];
        self::assertCount(1, $mine);
        self::assertSame($this->a['tenant'], $mine[0]['tenant_id']);
        self::assertSame($this->a['establishment'], $mine[0]['establishments'][0]['id']);
    }
}
