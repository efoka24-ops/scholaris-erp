<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\ExportsList;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Socle\Billing\Http\Resources\Resource;

/** Aides communes des contrôleurs : droits, contexte, réponses paginées et exports CSV. */
abstract class BillingController
{
    use ExportsList;

    protected const EXPORT_LIMIT = 5000;

    public function __construct(
        protected readonly JsonResponder $responder,
        protected readonly RequestContext $context,
        protected readonly BillingPolicy $policy,
    ) {
    }

    protected function authorizeRead(ServerRequestInterface $request): Principal
    {
        $principal = $this->context->principal($request);
        $this->policy->authorizeRead($principal, $this->context->establishmentId());

        return $principal;
    }

    /**
     * Lecture par le personnel (null) ou par un payeur en libre-service (identifiant de sa personne, D2) ; 403 sinon.
     * Le droit est contrôlé avant toute validation de la requête.
     */
    protected function staffOrSelf(ServerRequestInterface $request, SelfServiceScope $self, string $selfPermission, string ...$staffPermissions): ?string
    {
        $principal = $this->context->principal($request);
        $establishmentId = $this->context->establishmentId();
        if ($this->policy->can($principal, $establishmentId, ...$staffPermissions)) {
            return null;
        }
        $this->policy->authorize($principal, $establishmentId, $selfPermission);

        return $self->personId($principal);
    }

    protected function authorize(ServerRequestInterface $request, string ...$anyOf): Principal
    {
        $principal = $this->context->principal($request);
        $this->policy->authorize($principal, $this->context->establishmentId(), ...$anyOf);

        return $principal;
    }

    /** @param list<array<string, mixed>> $rows */
    protected function pageResponse(ServerRequestInterface $request, array $rows, ?string $next, Resource $resource): ResponseInterface
    {
        $links = ['next' => null];
        if ($next !== null) {
            $params = $request->getQueryParams();
            $params['cursor'] = $next;
            $links['next'] = $request->getUri()->getPath() . '?' . http_build_query($params);
        }

        return $this->responder->json(['data' => $resource->collection($rows), 'links' => $links, 'meta' => ['next_cursor' => $next]]);
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
            [$chunk, $cursor] = $page(ListQuery::fromRequest($request->withQueryParams($params), $filters));
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
    protected function param(ServerRequestInterface $request, string $name): string
    {
        return $this->context->routeParam($request, $name);
    }
}
