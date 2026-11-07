<?php

declare(strict_types=1);

namespace Tests\Feature;

use Scholaris\Core\RateLimit\Clock;
use Scholaris\Core\RateLimit\RateLimiter;
use Tests\Support\AppTestCase;

final class RateLimitTest extends AppTestCase
{
    public function testLaQuatriemeRequeteDUneFenetreDeTroisEstRefusee(): void
    {
        $this->boot([], ['api' => ['rate_limits' => [['GET', '#^/api/v1/health$#', 3, 60]]]]);

        for ($i = 1; $i <= 3; $i++) {
            self::assertSame(200, $this->call('GET', '/api/v1/health')->getStatusCode(), "appel $i");
        }
        $response = $this->call('GET', '/api/v1/health');

        self::assertSame(429, $response->getStatusCode());
        self::assertGreaterThanOrEqual(1, (int) $response->getHeaderLine('Retry-After'));
        self::assertSame(429, $this->json($response)['status']);
    }

    public function testLaFenetreSuivanteRepartDeZero(): void
    {
        $clock = new class () extends Clock {
            public int $time = 1_000_000;

            public function now(): int
            {
                return $this->time;
            }
        };
        $this->boot();
        $limiter = new RateLimiter($this->connections(), $clock);

        self::assertSame(0, $limiter->hit('k', 2, 60));
        self::assertSame(0, $limiter->hit('k', 2, 60));
        self::assertGreaterThan(0, $limiter->hit('k', 2, 60));

        $clock->time += 60;
        self::assertSame(0, $limiter->hit('k', 2, 60));
    }

    public function testLesClesDifferentesSontIndependantes(): void
    {
        $this->boot();
        $limiter = new RateLimiter($this->connections());

        self::assertSame(0, $limiter->hit('a', 1, 60));
        self::assertGreaterThan(0, $limiter->hit('a', 1, 60));
        self::assertSame(0, $limiter->hit('b', 1, 60));
    }
}
