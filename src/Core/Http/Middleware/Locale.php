<?php

declare(strict_types=1);

namespace Scholaris\Core\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Scholaris\Core\I18n\Translator;

/** Négocie la langue (Accept-Language : fr ou en), la dépose dans `locale` et répond avec Content-Language. */
final class Locale implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $locale = Translator::negotiate($request->getHeaderLine('Accept-Language'));

        return $handler->handle($request->withAttribute('locale', $locale))
            ->withHeader('Content-Language', $locale);
    }
}
