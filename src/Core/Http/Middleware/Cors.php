<?php

declare(strict_types=1);

namespace Scholaris\Core\Http\Middleware;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** CORS restreint aux origines déclarées (config api.cors_origins) ; aucune origine par défaut. */
final class Cors implements MiddlewareInterface
{
    /** @param list<string> $allowedOrigins */
    public function __construct(private readonly array $allowedOrigins)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $origin = $request->getHeaderLine('Origin');
        $allowed = $origin !== '' && in_array($origin, $this->allowedOrigins, true);

        if ($request->getMethod() === 'OPTIONS' && $request->hasHeader('Access-Control-Request-Method')) {
            $response = new Response(204);

            return $allowed ? $this->decorate($response, $origin) : $response;
        }

        $response = $handler->handle($request);

        return $allowed ? $this->decorate($response, $origin)->withAddedHeader('Vary', 'Origin') : $response;
    }

    private function decorate(ResponseInterface $response, string $origin): ResponseInterface
    {
        return $response
            ->withHeader('Access-Control-Allow-Origin', $origin)
            ->withHeader('Access-Control-Allow-Credentials', 'true')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type, Accept-Language, X-Establishment-Id, Idempotency-Key')
            ->withHeader('Access-Control-Expose-Headers', 'X-Trace-Id, Retry-After, Idempotent-Replayed')
            ->withHeader('Access-Control-Max-Age', '600');
    }
}
