<?php

declare(strict_types=1);

namespace Scholaris\Tests\Unit\Core;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Scholaris\Core\Http\Middleware\MethodOverride;

/** L'hébergement refuse PUT, PATCH et DELETE : l'application web les envoie en POST avec X-HTTP-Method-Override. */
final class MethodOverrideTest extends TestCase
{
    public function testPostAvecEnTeteDevientLaMethodeIndiquee(): void
    {
        foreach (['PATCH', 'put', 'DELETE'] as $override) {
            self::assertSame(strtoupper($override), $this->methodSeen('POST', $override));
        }
    }

    public function testSeulUnPostPeutEtreReecritEtVersTroisMethodesSeulement(): void
    {
        self::assertSame('GET', $this->methodSeen('GET', 'DELETE'), 'Un GET n\'est jamais réécrit.');
        self::assertSame('POST', $this->methodSeen('POST', 'GET'));
        self::assertSame('POST', $this->methodSeen('POST', 'OPTIONS'));
        self::assertSame('POST', $this->methodSeen('POST', null));
        self::assertSame('PATCH', $this->methodSeen('PATCH', null));
    }

    private function methodSeen(string $method, ?string $override): string
    {
        $request = new ServerRequest($method, '/api/v1/platform/settings');
        if ($override !== null) {
            $request = $request->withHeader(MethodOverride::HEADER, $override);
        }
        $handler = new class () implements RequestHandlerInterface {
            public string $seen = '';

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->seen = $request->getMethod();

                return (new Psr17Factory())->createResponse(204);
            }
        };
        (new MethodOverride())->process($request, $handler);

        return $handler->seen;
    }
}
