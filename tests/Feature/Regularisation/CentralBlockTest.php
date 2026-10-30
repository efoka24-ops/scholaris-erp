<?php

declare(strict_types=1);

namespace Tests\Feature\Regularisation;

use Scholaris\Core\Http\Middleware\EnforceTenantRestriction;

/**
 * Blocage central côté serveur d'un client en régularisation de paiement : toutes les routes authentifiées du socle et des
 * applications (le tableau est tiré du registre des routes, donc il suit les modules futurs), sauf la liste blanche.
 */
final class CentralBlockTest extends RegularisationCase
{
    /** Routes qu'un client bloqué doit pouvoir appeler : authentification, identité, régularisation, public, administration de plateforme. */
    private const ALLOWED_SAMPLES = [
        ['POST', '/auth/logout'],
        ['POST', '/auth/login'],
        ['POST', '/auth/2fa/verify'],
        ['GET', '/me'],
        ['GET', '/me/contexts'],
        ['POST', '/me/context'],
        ['GET', '/me/regularisation'],
        ['POST', '/me/regularisation/declarations'],
        ['GET', '/applications/higher/payments'],
        ['POST', '/applications/higher/payments'],
        ['GET', '/application-payments/x1/receipt'],
        ['GET', '/public/applications'],
        ['GET', '/health'],
        ['POST', '/platform/payments/webhook'],
    ];

    /** @return list<array{method: string, path: string, module: string, open: bool}> routes de client, sans la liste blanche */
    private function clientRoutes(): array
    {
        $guard = new EnforceTenantRestriction(new class () implements \Scholaris\Core\Http\TenantRestriction {
            public function levelFor(string $tenantId): ?string
            {
                return null;
            }
        });
        $routes = [];
        foreach ($this->kernel->routes()->all() as $route) {
            $path = substr($route['path'], strlen('/api/v1'));
            if (($route['options']['auth'] ?? true) === false || $guard->allowed($route['path'])) {
                continue;
            }
            $module = preg_match('#^/api/v1/([^/]+)#', $route['path'], $m) === 1 ? $m[1] : '?';
            $routes[] = ['method' => $route['method'], 'path' => (string) preg_replace('#\{[^}]+\}#', 'x1', $path), 'module' => $module, 'open' => false];
        }

        return $routes;
    }

    /**
     * Échantillon de chaque module (socle et applications) : la première lecture et la première écriture de chacun. Les routes
     * non bloquées exécutent leur contrôleur : on ne les parcourt pas toutes (les refus 423, eux, s'arrêtent avant tout contrôleur).
     *
     * @return list<array{method: string, path: string, module: string, open: bool}>
     */
    private function sample(): array
    {
        $seen = [];
        $out = [];
        foreach ($this->clientRoutes() as $route) {
            $kind = $route['module'] . ':' . ($route['method'] === 'GET' ? 'read' : 'write');
            if (!isset($seen[$kind]) && !str_contains($route['path'], 'x1')) {
                $seen[$kind] = true;
                $out[] = $route;
            }
        }

        return $out;
    }

    private function call(string $method, string $path, string $user = 'boss', ?string $tenant = null, string $language = 'fr'): \Psr\Http\Message\ResponseInterface
    {
        $client = $tenant === $this->other['tenant'] ? $this->other : $this->ipes;
        $headers = ['Accept-Language' => $language];
        $body = in_array($method, ['POST', 'PUT', 'PATCH'], true) ? [] : null;

        return $this->api($method, $path, $user, $client['tenant'], $body, $client['establishment'], $headers)[0];
    }

    public function testLeRegistreCouvreChaqueModuleDuSocleEtChaqueApplication(): void
    {
        $modules = array_unique(array_column($this->clientRoutes(), 'module'));
        self::assertGreaterThan(150, count($this->clientRoutes()));
        // Un échantillon de chaque module du socle et de chaque application doit être dans le tableau.
        foreach (['users', 'establishments', 'people', 'messages', 'documents', 'invoices', 'dashboards', 'workflows', 'imports', 'grades', 'higher', 'primary', 'nursery'] as $prefix) {
            self::assertNotEmpty(array_filter($modules, static fn (string $m): bool => str_starts_with($m, $prefix)), 'Aucune route ' . $prefix . '* dans le registre : ' . implode(', ', $modules));
        }
    }

    public function testBloqueToutesLesRoutesAuthentifieesSaufLaListeBlanche(): void
    {
        $this->lock('locked');
        $routes = $this->clientRoutes();
        $byModule = [];
        foreach ($routes as $route) {
            $response = $this->call($route['method'], $route['path']);
            $body = (string) $response->getBody();
            self::assertSame(423, $response->getStatusCode(), $route['method'] . ' ' . $route['path'] . ' : ' . $body);
            $json = json_decode($body, true);
            self::assertSame('https://api.scholaris.cm/errors/regularisation-required', $json['type']);
            self::assertSame('/regularisation', $json['regularisation_url']);
            self::assertSame('locked', $json['level']);
            $byModule[$route['module']] = ($byModule[$route['module']] ?? 0) + 1;
        }
        self::assertGreaterThan(8, count($byModule), 'Le blocage couvre de nombreux modules : ' . implode(', ', array_keys($byModule)));
    }

    public function testLaReponseNeContientAucuneDonneeDuClientEtEstBilingue(): void
    {
        $this->lock('locked');
        $fr = $this->call('GET', '/people/x1');
        $en = $this->call('GET', '/people/x1', 'boss', null, 'en');
        $frBody = json_decode((string) $fr->getBody(), true);
        $enBody = json_decode((string) $en->getBody(), true);
        self::assertSame('Régularisation de paiement requise', $frBody['title']);
        self::assertSame('Payment regularisation required', $enBody['title']);
        self::assertSame('application/problem+json; charset=utf-8', $fr->getHeaderLine('Content-Type'));
        self::assertSame(['detail', 'level', 'regularisation_url', 'status', 'title', 'trace_id', 'type'], $this->sortedKeys($frBody));
        self::assertStringNotContainsString($this->ipes['tenant'], (string) $fr->getBody());
        self::assertStringNotContainsString('IPES', (string) $fr->getBody());
    }

    /** @param array<string, mixed> $data @return list<string> */
    private function sortedKeys(array $data): array
    {
        $keys = array_keys($data);
        sort($keys);

        return $keys;
    }

    public function testLaListeBlancheResteAccessibleEnNiveauBloque(): void
    {
        $this->lock('locked');
        foreach (self::ALLOWED_SAMPLES as [$method, $path]) {
            $response = $this->call($method, $path);
            self::assertNotSame(423, $response->getStatusCode(), $method . ' ' . $path . ' : ' . $response->getBody());
        }
        [$me, $json] = $this->asBoss('GET', '/me/regularisation');
        self::assertSame(200, $me->getStatusCode());
        self::assertSame('locked', $json['data']['mode']);
        self::assertSame(401, $this->raw('GET', '/me', [])[0]->getStatusCode(), 'Sans compte : 401, pas 423.');
    }

    public function testEnLectureSeuleLesEcrituresSontRefuseesPartoutEtLaLectureReste(): void
    {
        $this->lock('read_only');
        $writes = 0;
        foreach ($this->clientRoutes() as $route) {
            if (in_array($route['method'], ['GET', 'HEAD', 'OPTIONS'], true)) {
                continue;
            }
            $response = $this->call($route['method'], $route['path']);
            ++$writes;
            $json = json_decode((string) $response->getBody(), true);
            self::assertSame(423, $response->getStatusCode(), $route['method'] . ' ' . $route['path'] . ' : ' . $response->getBody());
            self::assertSame('https://api.scholaris.cm/errors/subscription-suspended', $json['type']);
            self::assertSame('read_only', $json['level']);
        }
        self::assertGreaterThan(60, $writes);
        $reads = 0;
        foreach ($this->sample() as $route) {
            if ($route['method'] === 'GET') {
                self::assertNotSame(423, $this->call('GET', $route['path'])->getStatusCode(), 'Lecture et exports permis : ' . $route['path']);
                ++$reads;
            }
        }
        self::assertGreaterThan(10, $reads);
        $english = json_decode((string) $this->call('POST', '/messages', 'boss', null, 'en')->getBody(), true);
        self::assertSame('Subscription suspended: read and export only. Regularise the subscription to restore changes.', $english['detail']);
    }

    public function testEnLectureSeuleLaListeBlancheEcritToujours(): void
    {
        $this->lock('read_only');
        foreach (self::ALLOWED_SAMPLES as [$method, $path]) {
            self::assertNotSame(423, $this->call($method, $path)->getStatusCode(), $method . ' ' . $path);
        }
    }

    public function testLAdministrateurDePlateformeNEstJamaisBloque(): void
    {
        $this->lock('locked');
        self::assertSame(200, $this->admin('GET', '/platform/dashboard')[0]->getStatusCode());
        self::assertSame(200, $this->admin('GET', '/platform/regularisation')[0]->getStatusCode());
        foreach ($this->sample() as $route) {
            // Le compte de plateforme n'est jamais restreint par le blocage ; ses refus sont ceux des droits (403, 404…).
            self::assertNotSame(423, $this->api($route['method'], $route['path'], self::ROOT, null, in_array($route['method'], ['POST', 'PUT', 'PATCH'], true) ? [] : null, $this->ipes['establishment'])[0]->getStatusCode(), $route['method'] . ' ' . $route['path']);
        }
    }

    public function testUnAutreClientNEstJamaisBloque(): void
    {
        $this->lock('locked');
        $count = 0;
        foreach ($this->sample() as $route) {
            self::assertNotSame(423, $this->call($route['method'], $route['path'], 'boss', $this->other['tenant'])->getStatusCode(), $route['method'] . ' ' . $route['path']);
            ++$count;
        }
        self::assertGreaterThan(20, $count);
        self::assertSame('ok', $this->asBoss('GET', '/me/regularisation', null, $this->other['tenant'])[1]['data']['mode']);
    }

    public function testLeDeblocageParPaiementEstImmediat(): void
    {
        $this->lock('locked');
        self::assertSame(423, $this->call('GET', '/people/x1')->getStatusCode());
        [$paid] = $this->admin('POST', '/platform/payments', ['tenant_id' => $this->ipes['tenant'], 'app_key' => 'higher', 'months' => 1, 'channel' => 'cash', 'note' => 'Espèces au guichet']);
        self::assertSame(201, $paid->getStatusCode(), (string) $paid->getBody());
        self::assertNotSame(423, $this->call('GET', '/people/x1')->getStatusCode(), 'Le paiement débloque tout de suite, sans nouvelle connexion.');
        self::assertNotSame(423, $this->call('POST', '/messages')->getStatusCode());
    }

    public function testLeDeblocageManuelEstImmediatAussi(): void
    {
        $this->lock('read_only');
        self::assertSame(423, $this->call('POST', '/messages')->getStatusCode());
        [$unlock] = $this->admin('POST', '/platform/clients/' . $this->ipes['tenant'] . '/regularisation/unlock', ['reason' => 'Accord du directeur']);
        self::assertSame(200, $unlock->getStatusCode(), (string) $unlock->getBody());
        self::assertNotSame(423, $this->call('POST', '/messages')->getStatusCode());
    }

    public function testBlocageAutomatiqueApresLaGraceEtSansConfiguration(): void
    {
        // Sans réglage : niveau lecture seule. Ni l'impayé dans la grâce ni l'essai ne restreignent le socle.
        self::assertNotSame(423, $this->call('POST', '/messages')->getStatusCode());
        $this->lateAndSuspended();
        self::assertSame(423, $this->call('POST', '/messages')->getStatusCode());
        self::assertNotSame(423, $this->call('GET', '/messages')->getStatusCode());
    }

    public function testUneSuspensionOrdinaireNEstPasUnBlocageDePaiement(): void
    {
        [$suspend] = $this->admin('POST', '/platform/clients/' . $this->ipes['tenant'] . '/applications/higher/suspend', ['reason' => 'Demande de la direction']);
        self::assertSame(200, $suspend->getStatusCode(), (string) $suspend->getBody());
        self::assertNotSame(423, $this->call('POST', '/messages')->getStatusCode(), 'Hors impayé, le socle reste ouvert (les applications refusent seules).');
    }

    public function testUneSeuleApplicationAJourSuffitANePasRestreindreLeSocle(): void
    {
        [$open] = $this->admin('POST', '/platform/clients/' . $this->ipes['tenant'] . '/applications', ['app_key' => 'nursery']);
        self::assertContains($open->getStatusCode(), [201, 422, 409], (string) $open->getBody());
        if ($open->getStatusCode() !== 201) {
            self::markTestSkipped('Seconde application non ouvrable dans ce jeu de données.');
        }
        $this->admin('POST', '/platform/clients/' . $this->ipes['tenant'] . '/regularisation/lock', ['reason' => 'Une seule application', 'level' => 'locked', 'app_key' => 'higher']);
        self::assertNotSame(423, $this->call('GET', '/people/x1')->getStatusCode(), 'La seconde application est à jour : le socle n\'est pas restreint.');
        $this->admin('POST', '/platform/clients/' . $this->ipes['tenant'] . '/regularisation/lock', ['reason' => 'Les deux', 'level' => 'locked']);
        self::assertSame(423, $this->call('GET', '/people/x1')->getStatusCode());
    }

    public function testLeNiveauParDefautDeLaPlateformeBloqueToutAuPremierImpaye(): void
    {
        self::assertSame(200, $this->admin('PATCH', '/platform/settings', ['regularisation_default_level' => 1])[0]->getStatusCode());
        $this->lateAndSuspended();
        self::assertSame(423, $this->call('GET', '/people/x1')->getStatusCode());
        self::assertSame('locked', $this->asBoss('GET', '/me/regularisation')[1]['data']['mode']);
    }

    public function testEnRetardLaVueDuClientDonneLaDateEtLesJoursAvantBlocage(): void
    {
        $this->clock->day('2027-01-10');
        $this->kernel->container()->get(\Scholaris\Modules\Socle\Platform\Application\SubscriptionBilling::class)->run();
        self::assertSame('past_due', $this->entitlement($this->ipes['tenant'])['state']);
        $grace = (int) $this->connections()->pdo()->query("SELECT grace_days FROM platform_applications WHERE app_key = 'higher'")->fetchColumn();

        $view = $this->asBoss('GET', '/me/regularisation')[1]['data'];
        self::assertSame('past_due', $view['mode']);
        $item = $view['items'][0];
        self::assertSame('2027-01-06', $item['due_on']);
        self::assertSame(date('Y-m-d', strtotime('2027-01-06 +' . $grace . ' days')), $item['blocks_on']);
        self::assertSame($grace - 4, $item['days_left']);
        self::assertNotSame(423, $this->call('POST', '/messages')->getStatusCode(), 'Dans le délai de grâce, rien n\'est restreint.');
        self::assertSame($this->other['tenant'], $this->asBoss('GET', '/me/regularisation', null, $this->other['tenant'])[1]['data']['items'][0]['tenant_id'], 'Chacun ne voit que son client.');
    }
}
