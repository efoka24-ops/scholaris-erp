<?php

declare(strict_types=1);

namespace Tests\Isolation;

use Tests\Support\AppTestCase;

/**
 * Chaque route non publique, appelée par un utilisateur d'un autre client ou sans droit sur
 * l'établissement, doit répondre 401, 403 ou 404 (jamais de données). Échec = livraison bloquée.
 */
final class AllRoutesIsolationTest extends AppTestCase
{
    protected function setUp(): void
    {
        $this->boot();
        $this->access->grant('alice', 'e1');
    }

    /** @return list<array{method: string, path: string, options: array<string, bool>}> */
    private function protectedRoutes(): array
    {
        $routes = [];
        foreach ($this->kernel->routes()->all() as $route) {
            if (($route['options']['auth'] ?? true) === true) {
                $routes[] = ['method' => $route['method'], 'path' => preg_replace('/\{[^}]+\}/', 'x', $route['path']), 'options' => $route['options']];
            }
        }

        return $routes;
    }

    public function testToutesLesRoutesProtegeesRefusentLesAnonymes(): void
    {
        $routes = $this->protectedRoutes();
        self::assertNotSame([], $routes);

        foreach ($routes as $route) {
            $response = $this->call($route['method'], $route['path']);
            self::assertSame(401, $response->getStatusCode(), $route['method'] . ' ' . $route['path'] . ' doit exiger une authentification.');
        }
    }

    public function testToutesLesRoutesMetierRefusentUnUtilisateurSansDroitSurLEtablissement(): void
    {
        foreach ($this->protectedRoutes() as $route) {
            if (($route['options']['tenant'] ?? true) === false) {
                continue;
            }
            $response = $this->call($route['method'], $route['path'], [
                'Authorization' => 'Bearer mallory:autre-client',
                'X-Establishment-Id' => 'e1',
                'Idempotency-Key' => 'clef-isolation-0001',
            ]);

            self::assertContains(
                $response->getStatusCode(),
                [403, 404],
                $route['method'] . ' ' . $route['path'] . ' ne doit rien renvoyer à un utilisateur d\'un autre client.',
            );
        }
    }
}
