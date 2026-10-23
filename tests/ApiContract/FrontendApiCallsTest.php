<?php

declare(strict_types=1);

namespace Tests\ApiContract;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Scholaris\Core\Config\Config;
use Scholaris\Core\Kernel;

/**
 * Contrat front ↔ API : chaque appel HTTP écrit en clair dans web/src (http.get('/…'), client.post(`/…/${id}`)…) doit
 * correspondre à une route déclarée du RouteRegistry, avec la même méthode. Un appel sans route est un écran cassé.
 */
final class FrontendApiCallsTest extends TestCase
{
    /** @return array<string, list<string>> appel normalisé (« GET /people/{} ») => fichiers */
    public static function frontendCalls(): array
    {
        $root = dirname(__DIR__, 2) . '/web/src';
        $calls = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $name = str_replace('\\', '/', (string) $file);
            if (preg_match('/\.(ts|vue)$/', $name) !== 1) {
                continue;
            }
            $source = (string) file_get_contents($name);
            preg_match_all('/\b(?:http|client|api)\.(get|post|put|patch|delete)(?:<[^()]*?>)?\(\s*([\'"`])(\/[^\'"`]*)\2/s', $source, $matches, PREG_SET_ORDER);
            foreach ($matches as $m) {
                $path = (string) preg_replace('/\$\{[^}]+\}/', '{}', $m[3]);
                $path = (string) preg_replace('/\?.*$/', '', $path);
                $calls[strtoupper($m[1]) . ' ' . $path][] = substr($name, strlen($root) + 1);
            }
        }
        ksort($calls);

        return $calls;
    }

    /** @return array<string, true> */
    private static function backendRoutes(): array
    {
        $kernel = Kernel::boot(dirname(__DIR__, 2), Config::fromArray([
            'app' => ['api_prefix' => '/api/v1', 'timezone' => 'Africa/Douala', 'log_path' => 'storage/logs/test.log'],
        ]));
        $routes = [];
        foreach ($kernel->routes()->all() as $route) {
            $routes[$route['method'] . ' ' . preg_replace('/\{[^}]+\}/', '{}', substr($route['path'], strlen('/api/v1')))] = true;
        }

        return $routes;
    }

    public function testChaqueAppelDuFrontCorrespondAUneRouteDeLApi(): void
    {
        $calls = self::frontendCalls();
        self::assertGreaterThan(50, count($calls), 'Extraction des appels du front.');
        $routes = self::backendRoutes();

        $missing = [];
        foreach ($calls as $call => $files) {
            // Segment dynamique en fin de chemin (`/vacation-contracts/${id}/${action}`) : au moins une route doit convenir.
            $pattern = '#^' . str_replace('\{\}', '[^ ]+', preg_quote($call, '#')) . '$#';
            $found = isset($routes[$call]) || array_filter(array_keys($routes), static fn (string $r): bool => preg_match($pattern, $r) === 1) !== [];
            if (!$found) {
                $missing[] = $call . '  (' . implode(', ', array_unique($files)) . ')';
            }
        }

        self::assertSame([], $missing, 'Appels du front sans route côté API.');
    }
}
