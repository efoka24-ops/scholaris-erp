<?php

declare(strict_types=1);

namespace Tests\Support\Performance;

use Nyholm\Psr7\ServerRequest;
use Scholaris\Core\Config\Config;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Database\Migrator;
use Scholaris\Core\Kernel;
use Scholaris\Core\Security\Authenticator;
use Scholaris\Core\Security\EstablishmentAccess;
use Tests\Support\StubAccess;
use Tests\Support\StubAuthenticator;
use Tests\Support\TestController;

/**
 * Mesure LOCALE de latence : le noyau HTTP est appelé en processus (sans serveur web ni réseau) sur une base SQLite en mémoire.
 * Ce n'est PAS un résultat k6 : il ne mesure ni le serveur web, ni PHP-FPM, ni MySQL, ni le réseau. Il sert de garde-fou de
 * régression sur le coût applicatif d'une requête (routage, intergiciels, accès cloisonné aux données).
 */
final class LatencyProbe
{
    private Kernel $kernel;

    public function __construct(int $seedRows = 500)
    {
        $access = new StubAccess();
        $access->grant('alice', 'e1');
        $config = Config::fromArray([
            'app' => ['name' => 'SCHOLARIS', 'debug' => false, 'timezone' => 'Africa/Douala', 'api_prefix' => '/api/v1', 'log_path' => 'storage/logs/test.log'],
            'database' => ['driver' => 'sqlite', 'database' => ':memory:'],
            'api' => ['cors_origins' => [], 'idempotent_paths' => [], 'rate_limits' => []],
        ]);
        $this->kernel = Kernel::boot(dirname(__DIR__, 3), $config, [Authenticator::class => new StubAuthenticator(), EstablishmentAccess::class => $access]);
        $this->kernel->container()->get(Migrator::class)->migrate();
        $this->kernel->routes()->add('GET', '/_test/things', [TestController::class, 'things']);

        $pdo = $this->kernel->container()->get(ConnectionFactory::class)->pdo();
        $pdo->exec('CREATE TABLE IF NOT EXISTS things (id VARCHAR(26) PRIMARY KEY, tenant_id VARCHAR(40), establishment_id VARCHAR(40), name VARCHAR(80))');
        $pdo->beginTransaction();
        $insert = $pdo->prepare('INSERT INTO things (id, tenant_id, establishment_id, name) VALUES (?, ?, ?, ?)');
        for ($i = 0; $i < $seedRows; $i++) {
            $insert->execute([sprintf('T%07d', $i), 't1', 'e1', 'Ligne ' . $i]);
        }
        $pdo->commit();
    }

    /**
     * @return array{requests: int, errors: int, min_ms: float, p50_ms: float, p95_ms: float, p99_ms: float, max_ms: float}
     */
    public function measure(string $path, int $requests, bool $authenticated, int $expectedStatus = 200): array
    {
        $headers = $authenticated ? ['Authorization' => 'Bearer alice:t1', 'X-Establishment-Id' => 'e1'] : [];
        // Échauffement : premières résolutions du conteneur et des tables, hors mesure.
        for ($i = 0; $i < 5; $i++) {
            $this->kernel->handle(new ServerRequest('GET', $path, $headers));
        }
        $durations = [];
        $errors = 0;
        for ($i = 0; $i < $requests; $i++) {
            $start = hrtime(true);
            $response = $this->kernel->handle(new ServerRequest('GET', $path, $headers));
            $durations[] = (hrtime(true) - $start) / 1_000_000;
            if ($response->getStatusCode() !== $expectedStatus) {
                $errors++;
            }
        }
        sort($durations);
        $at = static fn (float $q): float => round($durations[(int) min(count($durations) - 1, max(0, (int) ceil($q * count($durations)) - 1))], 3);

        return [
            'requests' => $requests,
            'errors' => $errors,
            'min_ms' => round($durations[0], 3),
            'p50_ms' => $at(0.50),
            'p95_ms' => $at(0.95),
            'p99_ms' => $at(0.99),
            'max_ms' => round($durations[count($durations) - 1], 3),
        ];
    }
}
