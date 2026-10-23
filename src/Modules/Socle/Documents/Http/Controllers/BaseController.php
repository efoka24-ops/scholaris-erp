<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Documents\Http\Controllers;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Scholaris\Core\Http\ExportsList;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Tenancy\TenantContext;
use Scholaris\Modules\Socle\Documents\Http\Policies\DocumentPolicy;

/** Socle commun des contrôleurs : identité, contexte client + établissement, autorisation, pagination, réponses binaires. */
abstract class BaseController
{
    use ExportsList;

    protected readonly JsonResponder $responder;
    protected readonly TenantContext $context;
    protected readonly DocumentPolicy $policy;
    private readonly ResponseFactoryInterface $responses;
    private readonly StreamFactoryInterface $streams;

    public function __construct(HttpKit $kit)
    {
        $this->responder = $kit->responder;
        $this->context = $kit->context;
        $this->policy = $kit->policy;
        $this->responses = $kit->responses;
        $this->streams = $kit->streams;
    }

    /** Exige au moins une des permissions sur l'établissement actif ; renvoie la personne authentifiée. */
    protected function authorize(ServerRequestInterface $request, string ...$anyOf): Principal
    {
        $principal = $request->getAttribute('principal');
        if (!$principal instanceof Principal) {
            throw HttpException::unauthorized();
        }
        $this->policy->authorize($principal, $this->context->establishmentId(), ...$anyOf);

        return $principal;
    }

    protected function tenant(): string
    {
        return $this->context->tenantId();
    }

    protected function establishment(): string
    {
        return $this->context->establishmentId();
    }

    protected function id(ServerRequestInterface $request): string
    {
        return (string) $request->getAttribute('id');
    }

    /**
     * @param callable(int, int): list<array<string, mixed>> $fetch reçoit (limite, décalage)
     * @param callable(array<string, mixed>): array<string, mixed> $present
     */
    protected function page(ServerRequestInterface $request, ListQuery $query, callable $fetch, callable $present): ResponseInterface
    {
        $offset = 0;
        if ($query->cursor !== null) {
            $decoded = ListQuery::decodeCursor($query->cursor);
            if ($decoded === null || preg_match('/^\d{1,9}$/', $decoded) !== 1) {
                throw HttpException::badRequest('Curseur invalide ou périmé.');
            }
            $offset = (int) $decoded;
        }
        $rows = $fetch($query->perPage + 1, $offset);
        $more = count($rows) > $query->perPage;
        $rows = array_slice($rows, 0, $query->perPage);
        $next = $more ? ListQuery::encodeCursor((string) ($offset + $query->perPage)) : null;

        $links = ['next' => null];
        if ($next !== null) {
            $params = $request->getQueryParams();
            $params['cursor'] = $next;
            $links['next'] = $request->getUri()->getPath() . '?' . http_build_query($params);
        }

        return $this->responder->json(['data' => array_map($present, $rows), 'links' => $links, 'meta' => ['next_cursor' => $next]]);
    }

    /**
     * Export d'une liste (CSV par défaut, Excel avec ?format=xlsx).
     *
     * @param list<string> $headers
     * @param iterable<list<mixed>> $rows
     */
    protected function exportFile(ServerRequestInterface $request, string $filename, array $headers, iterable $rows): ResponseInterface
    {
        return $this->csvResponse($this->responses, $this->streams, $filename, $headers, $rows, $request);
    }

    protected function binary(string $body, string $contentType, string $filename, int $status = 200): ResponseInterface
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename);

        return $this->responses->createResponse($status)
            ->withHeader('Content-Type', $contentType)
            ->withHeader('Content-Disposition', 'inline; filename="' . $safe . '"')
            ->withHeader('Cache-Control', 'no-store')
            ->withBody($this->streams->createStream($body));
    }

    protected function ip(ServerRequestInterface $request): ?string
    {
        $remote = $request->getServerParams()['REMOTE_ADDR'] ?? null;

        return is_string($remote) ? $remote : null;
    }
}
