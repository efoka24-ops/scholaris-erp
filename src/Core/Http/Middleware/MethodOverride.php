<?php

declare(strict_types=1);

namespace Scholaris\Core\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Un POST portant l'en-tête X-HTTP-Method-Override (PUT, PATCH ou DELETE) est traité comme cette méthode. L'hébergement de
 * production (Camoo) refuse PUT, PATCH et DELETE par un 403 HTML avant PHP : l'application web les envoie donc en POST.
 * Seul un POST peut être réécrit (jamais un GET), et seulement vers ces trois méthodes.
 */
final class MethodOverride implements MiddlewareInterface
{
    public const HEADER = 'X-HTTP-Method-Override';
    private const ALLOWED = ['PUT', 'PATCH', 'DELETE'];

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getMethod() === 'POST') {
            $override = strtoupper(trim($request->getHeaderLine(self::HEADER)));
            if (in_array($override, self::ALLOWED, true)) {
                $request = $request->withMethod($override)->withoutHeader(self::HEADER);
            }
        }

        return $handler->handle($request);
    }
}
