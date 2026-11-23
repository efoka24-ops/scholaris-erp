<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Http;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Socle\People\Application\AccessGuard;
use Scholaris\Modules\Socle\People\Application\PersonService;

/** Socle des contrôleurs : réponses, erreurs à membres d'extension, contrôle d'accès à une fiche. */
abstract class BaseController
{
    public function __construct(
        protected readonly JsonResponder $responder,
        protected readonly RequestContext $context,
        protected readonly AccessGuard $guard,
        protected readonly PersonService $people,
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

    /**
     * @param list<array<string, mixed>> $items
     * @param array<string, mixed> $meta
     */
    protected function listing(array $items, array $meta = []): ResponseInterface
    {
        return $this->ok(['data' => $items, 'meta' => $meta]);
    }

    /**
     * @param list<array<string, mixed>> $items
     */
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

    /**
     * Erreur RFC 9457 avec membres d'extension.
     *
     * @param array<string, mixed> $extra
     */
    protected function problem(ServerRequestInterface $request, int $status, string $title, string $detail, string $slug, array $extra = []): ResponseInterface
    {
        return $this->responder->json([
            'type' => 'https://api.scholaris.cm/errors/' . $slug,
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
            'trace_id' => (string) $request->getAttribute('trace_id', ''),
        ] + $extra, $status)->withHeader('Content-Type', 'application/problem+json; charset=utf-8');
    }

    protected function principal(ServerRequestInterface $request): Principal
    {
        return $this->context->principal($request);
    }

    protected function param(ServerRequestInterface $request, string $name): string
    {
        return (string) $request->getAttribute($name);
    }

    /**
     * Fiche d'une personne du client, visible par l'appelant : 404 sinon (on ne révèle pas son existence).
     *
     * @return array<string, mixed>
     */
    protected function visiblePerson(ServerRequestInterface $request, string $personId): array
    {
        $person = $this->people->get($this->context->tenantId(), $personId);
        $this->guard->requireView($this->principal($request), $this->context->establishmentId(), $personId);

        return $person;
    }

    /**
     * Fiche d'une personne du client, modifiable par l'appelant (people.manage).
     *
     * @return array<string, mixed>
     */
    protected function managedPerson(ServerRequestInterface $request, string $personId): array
    {
        $this->guard->requireManage($this->principal($request), $this->context->establishmentId());

        return $this->people->get($this->context->tenantId(), $personId);
    }

    /** @return list<string>|null personnes visibles ; null = tout le client */
    protected function visibleScope(ServerRequestInterface $request): ?array
    {
        $principal = $this->principal($request);

        return $this->guard->hasFullRead($principal, $this->context->establishmentId()) ? null : $this->guard->visibleIds($principal);
    }

    /** Requête de la page d'un export : pages de 200 lignes, mêmes filtres, curseur éventuel. */
    protected function exportPage(ServerRequestInterface $request, ?string $cursor): ServerRequestInterface
    {
        $params = $request->getQueryParams();
        $params['per_page'] = (string) ListQuery::MAX_PER_PAGE;
        unset($params['cursor']);
        if ($cursor !== null) {
            $params['cursor'] = $cursor;
        }

        return $request->withQueryParams($params);
    }

    /** @param list<string> $filters */
    protected function query(ServerRequestInterface $request, array $filters): ListQuery
    {
        return ListQuery::fromRequest($request, $filters, []);
    }
}
