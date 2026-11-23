<?php

declare(strict_types=1);

namespace Tests\Feature;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Scholaris\Core\Config\Config;
use Scholaris\Core\Kernel;

final class HealthTest extends TestCase
{
    private function kernel(): Kernel
    {
        $config = Config::fromArray([
            'app' => [
                'name' => 'SCHOLARIS',
                'debug' => false,
                'timezone' => 'Africa/Douala',
                'api_prefix' => '/api/v1',
                'log_path' => 'storage/logs/test.log',
            ],
            'database' => ['driver' => 'sqlite', 'database' => ':memory:'],
        ]);

        return Kernel::boot(dirname(__DIR__, 2), $config);
    }

    private function call(string $method, string $path): ResponseInterface
    {
        return $this->kernel()->handle(new ServerRequest($method, $path));
    }

    public function testHealthRepondOkAvecLeStatutDeLaBase(): void
    {
        $response = $this->call('GET', '/api/v1/health');
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ok', $body['status']);
        self::assertSame('ok', $body['database']);
        self::assertStringStartsWith('+01:00', substr((string) $body['time'], -6));
        self::assertArrayNotHasKey('php', $body, 'La version de PHP ne doit pas fuiter hors debug.');
    }

    public function testRouteInconnueRenvoieUnProblemDetails404(): void
    {
        $response = $this->call('GET', '/api/v1/inconnue');
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(404, $response->getStatusCode());
        self::assertStringStartsWith('application/problem+json', $response->getHeaderLine('Content-Type'));
        self::assertSame(404, $body['status']);
        self::assertNotEmpty($body['trace_id']);
        self::assertSame($body['trace_id'], $response->getHeaderLine('X-Trace-Id'));
    }

    public function testMethodeNonAutoriseeRenvoie405AvecAllow(): void
    {
        $response = $this->call('POST', '/api/v1/health');

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('GET', $response->getHeaderLine('Allow'));
    }

    public function testEntetesDeSecuriteSontPresents(): void
    {
        $response = $this->call('GET', '/api/v1/health');

        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
        self::assertNotSame('', $response->getHeaderLine('Content-Security-Policy'));
    }
}
