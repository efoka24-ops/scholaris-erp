<?php

declare(strict_types=1);

namespace Scholaris\Core\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/** Fabrique des réponses JSON (données) et problem+json (erreurs, RFC 9457). */
final class JsonResponder
{
    public function __construct(
        private readonly ResponseFactoryInterface $responses,
        private readonly StreamFactoryInterface $streams,
    ) {
    }

    /** @param array<mixed> $data */
    public function json(array $data, int $status = 200): ResponseInterface
    {
        return $this->build($data, $status, 'application/json');
    }

    public function problem(HttpException $e, string $traceId): ResponseInterface
    {
        $payload = [
            'type' => $e->type,
            'title' => $e->title,
            'status' => $e->status,
            'detail' => $e->getMessage(),
            'trace_id' => $traceId,
        ];
        if ($e->errors !== []) {
            $payload['errors'] = $e->errors;
        }

        $response = $this->build($payload, $e->status, 'application/problem+json');
        foreach ($e->headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    /** @param array<mixed> $data */
    private function build(array $data, int $status, string $contentType): ResponseInterface
    {
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $this->responses->createResponse($status)
            ->withHeader('Content-Type', $contentType . '; charset=utf-8')
            ->withBody($this->streams->createStream($json));
    }
}
