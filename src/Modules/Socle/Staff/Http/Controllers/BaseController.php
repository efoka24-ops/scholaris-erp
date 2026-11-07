<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Staff\Http\Controllers;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\ExportsList;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Socle\Staff\Application\StaffAccess;
use Scholaris\Modules\Socle\Staff\Http\RequestContext;

/** Socle des contrôleurs : réponses, pagination, contexte et droits. */
abstract class BaseController
{
    use ExportsList;

    protected const EXPORT_LIMIT = 5000;

    public function __construct(
        protected readonly JsonResponder $responder,
        protected readonly RequestContext $context,
        protected readonly StaffAccess $access,
    ) {
    }

    /** @param array<mixed> $payload */
    protected function ok(array $payload, int $status = 200): ResponseInterface
    {
        return $this->responder->json($payload, $status);
    }

    protected function noContent(): ResponseInterface
    {
        return new Response(204);
    }

    /** @param list<array<string, mixed>> $items */
    protected function page(ServerRequestInterface $request, array $items, ?string $next): ResponseInterface
    {
        $links = ['next' => null];
        if ($next !== null) {
            $params = $request->getQueryParams();
            $params['cursor'] = $next;
            $links['next'] = $request->getUri()->getPath() . '?' . http_build_query($params);
        }

        return $this->ok(['data' => $items, 'links' => $links, 'meta' => ['next_cursor' => $next]]);
    }

    protected function principal(ServerRequestInterface $request): Principal
    {
        return $this->context->principal($request);
    }

    protected function param(ServerRequestInterface $request, string $name): string
    {
        return (string) $request->getAttribute($name);
    }

    /** @param list<string> $filters */
    protected function query(ServerRequestInterface $request, array $filters): ListQuery
    {
        return ListQuery::fromRequest($request, $filters, []);
    }

    /**
     * Toutes les lignes d'une liste (pages de 200, plafond d'export), avec les filtres de la requête.
     *
     * @param list<string> $filters filtres autorisés
     * @param callable(ListQuery): array{0: list<array<string, mixed>>, 1: ?string} $page
     * @return list<array<string, mixed>>
     */
    protected function collectAll(ServerRequestInterface $request, array $filters, callable $page): array
    {
        $rows = [];
        $cursor = null;
        do {
            $params = $request->getQueryParams();
            $params['per_page'] = (string) ListQuery::MAX_PER_PAGE;
            unset($params['cursor']);
            if ($cursor !== null) {
                $params['cursor'] = $cursor;
            }
            [$chunk, $cursor] = $page($this->query($request->withQueryParams($params), $filters));
            array_push($rows, ...$chunk);
        } while ($cursor !== null && count($rows) < self::EXPORT_LIMIT);

        return array_slice($rows, 0, self::EXPORT_LIMIT);
    }

    /**
     * @param list<string> $headers
     * @param list<list<mixed>> $lines
     */
    protected function csv(string $filename, array $headers, array $lines, ?ServerRequestInterface $request = null): ResponseInterface
    {
        return $this->csvResponse(new Psr17Factory(), new Psr17Factory(), $filename, $headers, $lines, $request);
    }

    /** @return list<string>|null fiches visibles pour les vacations ; null = tout l'établissement */
    protected function vacationsScope(ServerRequestInterface $request): ?array
    {
        $principal = $this->principal($request);

        return $this->access->hasVacationsRead($principal, $this->context->establishmentId()) ? null : $this->access->ownStaffIds($principal);
    }

    /** 404 si l'appelant n'a pas le droit de voir les vacations de cette fiche. */
    protected function requireVacationsView(ServerRequestInterface $request, string $staffId, string $message): void
    {
        $principal = $this->principal($request);
        if (!$this->access->hasVacationsRead($principal, $this->context->establishmentId()) && !$this->access->isOwn($principal, $staffId)) {
            throw HttpException::notFound($message);
        }
    }
}
