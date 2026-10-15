<?php

declare(strict_types=1);

namespace Scholaris\Core\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Authenticator;

/** Exige une personne authentifiée sauf pour les routes publiques ; dépose `principal` dans la requête. */
final class Authenticate implements MiddlewareInterface
{
    public function __construct(private readonly Authenticator $authenticator)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $options = (array) $request->getAttribute('route_options', []);
        if (($options['auth'] ?? true) === false) {
            return $handler->handle($request);
        }

        $principal = $this->authenticator->authenticate($request);
        if ($principal === null) {
            throw HttpException::unauthorized();
        }

        return $handler->handle($request->withAttribute('principal', $principal));
    }
}
