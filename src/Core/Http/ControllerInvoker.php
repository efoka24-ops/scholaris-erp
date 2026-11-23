<?php

declare(strict_types=1);

namespace Scholaris\Core\Http;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Dernier maillon : appelle le contrôleur [Classe, méthode] résolu par RouteResolver, obtenu du conteneur PSR-11. */
final class ControllerInvoker implements RequestHandlerInterface
{
    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var array{0: class-string, 1: string} $route */
        $route = $request->getAttribute('route_handler');
        $controller = $this->container->get($route[0]);

        return $controller->{$route[1]}($request);
    }
}
