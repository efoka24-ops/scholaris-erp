<?php

declare(strict_types=1);

namespace Tests\Support;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Scholaris\Core\Config\Config;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Database\Migrator;
use Scholaris\Core\Kernel;
use Scholaris\Core\Security\Authenticator;
use Scholaris\Core\Security\EstablishmentAccess;

/**
 * Base des tests applicatifs : un Kernel par test, base SQLite en mémoire migrée, authentification
 * et droits d'établissement remplacés par des doublures déterministes.
 *
 * Jeton de test : « Bearer <utilisateur>:<client> ». Droits : voir StubAccess.
 */
abstract class AppTestCase extends TestCase
{
    protected Kernel $kernel;
    protected StubAccess $access;

    /** @param array<string, mixed> $overrides */
    protected function boot(array $overrides = [], array $configOverrides = []): void
    {
        $this->access = new StubAccess();
        $config = Config::fromArray(array_replace_recursive([
            'app' => [
                'name' => 'SCHOLARIS',
                'debug' => false,
                'timezone' => 'Africa/Douala',
                'api_prefix' => '/api/v1',
                'log_path' => 'storage/logs/test.log',
            ],
            'database' => ['driver' => 'sqlite', 'database' => ':memory:'],
            'api' => [
                'cors_origins' => ['https://app.scholaris.test'],
                'idempotent_paths' => ['#^/api/v1/_test/payments$#'],
                'rate_limits' => [],
            ],
        ], $configOverrides));

        $this->kernel = Kernel::boot(dirname(__DIR__, 2), $config, $overrides + [
            Authenticator::class => new StubAuthenticator(),
            EstablishmentAccess::class => $this->access,
        ]);
        $this->kernel->container()->get(Migrator::class)->migrate();
        $this->kernel->routes()->add('GET', '/_test/things', [TestController::class, 'things']);
        $this->kernel->routes()->add('POST', '/_test/payments', [TestController::class, 'pay']);
        $this->kernel->routes()->add('GET', '/_test/orphan', [TestController::class, 'orphan'], ['public' => true]);
        $this->kernel->routes()->add('GET', '/_test/boom', [TestController::class, 'boom'], ['public' => true]);
        $this->kernel->routes()->add('GET', '/_test/invalid', [TestController::class, 'invalid'], ['public' => true]);
        $this->kernel->routes()->add('GET', '/_test/me', [TestController::class, 'me'], ['tenant' => false]);
    }

    protected function connections(): ConnectionFactory
    {
        return $this->kernel->container()->get(ConnectionFactory::class);
    }

    /** @param array<string, string> $headers */
    protected function call(string $method, string $path, array $headers = [], string $body = ''): ResponseInterface
    {
        return $this->kernel->handle(new ServerRequest($method, $path, $headers, $body));
    }

    /** @return array<string, mixed> */
    protected function json(ResponseInterface $response): array
    {
        return (array) json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }
}
