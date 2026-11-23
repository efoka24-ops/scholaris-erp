<?php

declare(strict_types=1);

namespace Scholaris\Core\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Chaîne de middlewares PSR-15 : le premier de la liste est le plus externe.
 */
final class Pipeline implements RequestHandlerInterface
{
    /** @param list<MiddlewareInterface> $middlewares */
    public function __construct(
        private readonly array $middlewares,
        private readonly RequestHandlerInterface $fallback,
        private readonly int $position = 0,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!isset($this->middlewares[$this->position])) {
            return $this->fallback->handle($request);
        }

        $next = new self($this->middlewares, $this->fallback, $this->position + 1);

        return $this->middlewares[$this->position]->process($request, $next);
    }
}
