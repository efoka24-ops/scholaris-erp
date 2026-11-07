<?php

declare(strict_types=1);

namespace Scholaris\Core\Http\Middleware;

use FastRoute\Dispatcher;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Scholaris\Core\Http\HttpException;

/**
 * Résout la route (FastRoute) puis dépose dans la requête le gestionnaire (`route_handler`),
 * les options (`route_options`) et les paramètres d'URL. Les middlewares suivants s'en servent.
 */
final class RouteResolver implements MiddlewareInterface
{
    public function __construct(private readonly Dispatcher $dispatcher)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $result = $this->dispatcher->dispatch($request->getMethod(), rawurldecode($request->getUri()->getPath()));

        if ($result[0] === Dispatcher::NOT_FOUND) {
            throw HttpException::notFound();
        }
        if ($result[0] === Dispatcher::METHOD_NOT_ALLOWED) {
            throw new HttpException(
                405,
                'Méthode non autorisée',
                'Méthode non autorisée pour cette ressource.',
                headers: ['Allow' => implode(', ', $result[1])],
            );
        }

        [$route, $options] = $result[1];
        foreach ($result[2] as $name => $value) {
            $request = $request->withAttribute($name, $value);
        }

        return $handler->handle(
            $request->withAttribute('route_handler', $route)->withAttribute('route_options', $options),
        );
    }
}
