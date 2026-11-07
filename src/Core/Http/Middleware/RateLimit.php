<?php

declare(strict_types=1);

namespace Scholaris\Core\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\RateLimit\RateLimiter;
use Scholaris\Core\Security\Principal;

/**
 * Limitation de débit par adresse IP, et par utilisateur quand il est connu, selon des règles
 * `[methode, motif regex de chemin, limite, fenêtre en secondes]` ; la première règle qui correspond s'applique.
 */
final class RateLimit implements MiddlewareInterface
{
    /** @param list<array{0: string, 1: string, 2: int, 3: int}> $rules */
    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly array $rules,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        foreach ($this->rules as [$method, $pattern, $limit, $window]) {
            if ($method !== '*' && $method !== $request->getMethod()) {
                continue;
            }
            if (preg_match($pattern, $path) !== 1) {
                continue;
            }

            $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? 'inconnue');
            $retryAfter = $this->limiter->hit('ip|' . $pattern . '|' . $ip, $limit, $window);
            $principal = $request->getAttribute('principal');
            if ($retryAfter === 0 && $principal instanceof Principal) {
                $retryAfter = $this->limiter->hit('user|' . $pattern . '|' . $principal->userId, $limit, $window);
            }
            if ($retryAfter > 0) {
                throw HttpException::tooManyRequests($retryAfter);
            }

            break;
        }

        return $handler->handle($request);
    }
}
