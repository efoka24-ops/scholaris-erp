<?php

declare(strict_types=1);

namespace Scholaris\Core\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Scholaris\Core\Http\HttpException;

/**
 * Plafond de taille des corps JSON (OWASP API4) : un corps JSON démesuré est refusé (413) avant tout décodage.
 * Les imports (text/csv) et téléversements (multipart/form-data) gardent leurs propres contrôles et les limites de PHP.
 */
final class JsonBodyLimit implements MiddlewareInterface
{
    public function __construct(private readonly int $maxBytes = 2_097_152)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->maxBytes > 0 && str_contains(strtolower($request->getHeaderLine('Content-Type')), 'json')) {
            $declared = $request->getHeaderLine('Content-Length');
            $size = ctype_digit($declared) ? (int) $declared : $request->getBody()->getSize();
            if ($size !== null && $size > $this->maxBytes) {
                throw new HttpException(413, 'Corps trop volumineux', 'Le corps JSON dépasse la taille autorisée (' . $this->maxBytes . ' octets).', 'https://api.scholaris.cm/errors/payload-too-large');
            }
        }

        return $handler->handle($request);
    }
}
