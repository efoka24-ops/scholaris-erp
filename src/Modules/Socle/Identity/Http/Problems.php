<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Http;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;

/**
 * Erreurs RFC 9457 avec membres d'extension (`attempts_left`, `locked_until`…) que le gestionnaire d'erreurs du noyau
 * ne sait pas porter. Même forme que les autres erreurs : type, title, status, detail, trace_id.
 */
final class Problems
{
    private const BASE = 'https://api.scholaris.cm/errors/';

    public function __construct(private readonly JsonResponder $responder)
    {
    }

    /** Réponse 204 sans corps. */
    public function noContent(): ResponseInterface
    {
        return new Response(204);
    }

    /**
     * @param array<string, mixed> $extra
     * @param array<string, string> $headers
     */
    public function build(ServerRequestInterface $request, int $status, string $title, string $detail, string $slug, array $extra = [], array $headers = []): ResponseInterface
    {
        $payload = [
            'type' => self::BASE . $slug,
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
            'trace_id' => (string) $request->getAttribute('trace_id', ''),
        ] + $extra;

        $response = $this->responder->json($payload, $status)->withHeader('Content-Type', 'application/problem+json; charset=utf-8');
        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }
}
