<?php

declare(strict_types=1);

namespace Scholaris\Core\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Support\Ulid;
use Scholaris\Core\Tenancy\MissingTenantContext;
use Throwable;

/**
 * Convertit toute exception en réponse RFC 9457 avec identifiant de trace.
 * Les erreurs internes (500) sont journalisées et ne révèlent rien en production.
 */
final class ErrorHandler implements MiddlewareInterface
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly LoggerInterface $logger,
        private readonly bool $debug,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $traceId = (string) new Ulid();
        $request = $request->withAttribute('trace_id', $traceId);

        try {
            return $handler->handle($request)->withHeader('X-Trace-Id', $traceId);
        } catch (HttpException $e) {
            return $this->responder->problem($e, $traceId)->withHeader('X-Trace-Id', $traceId);
        } catch (MissingTenantContext $e) {
            $this->logger->warning($e->getMessage(), ['trace_id' => $traceId]);

            return $this->responder->problem(HttpException::forbidden(), $traceId)->withHeader('X-Trace-Id', $traceId);
        } catch (Throwable $e) {
            $this->logger->error('Erreur non gérée', ['trace_id' => $traceId, 'exception' => $e]);
            $detail = $this->debug ? $e->getMessage() : 'Une erreur interne est survenue.';
            $problem = new HttpException(500, 'Erreur interne', $detail, 'https://api.scholaris.cm/errors/internal');

            return $this->responder->problem($problem, $traceId)->withHeader('X-Trace-Id', $traceId);
        }
    }
}
