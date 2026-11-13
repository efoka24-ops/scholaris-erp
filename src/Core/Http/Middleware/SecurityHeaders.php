<?php

declare(strict_types=1);

namespace Scholaris\Core\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** En-têtes de sécurité exigés par le CDC (API sans contenu actif). */
final class SecurityHeaders implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request)
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()')
            ->withHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'")
            ->withHeader('Cache-Control', 'no-store');

        // HSTS uniquement sur une connexion sécurisée : tant que le site n'a pas de certificat, il reste joignable en HTTP.
        if ($this->isSecure($request)) {
            $response = $response->withHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    private function isSecure(ServerRequestInterface $request): bool
    {
        if ($request->getUri()->getScheme() === 'https') {
            return true;
        }

        return strtolower($request->getHeaderLine('X-Forwarded-Proto')) === 'https';
    }
}
