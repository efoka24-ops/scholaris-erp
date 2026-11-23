<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Http\Controllers;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\ExportsList;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Socle\Assessment\Application\VersionConflict;
use Scholaris\Modules\Socle\Assessment\Http\AssessmentPolicy;
use Scholaris\Modules\Socle\Assessment\Http\RequestContext;
use Scholaris\Modules\Socle\Assessment\Http\Resources\Resource;

/** Aides communes des contrôleurs : droits, contexte, paramètres de requête, réponses paginées et conflits de version. */
abstract class AssessmentController
{
    use ExportsList;

    protected const EXPORT_LIMIT = 5000;

    public function __construct(
        protected readonly JsonResponder $responder,
        protected readonly RequestContext $context,
        protected readonly AssessmentPolicy $policy,
    ) {
    }

    protected function authorizeRead(ServerRequestInterface $request): Principal
    {
        $principal = $this->context->principal($request);
        $this->policy->authorizeRead($principal, $this->context->establishmentId());

        return $principal;
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

    /** Paramètre de requête texte ; 422 s'il est obligatoire et absent. */
    protected function query(ServerRequestInterface $request, string $name, bool $required = false): ?string
    {
        $value = $request->getQueryParams()[$name] ?? null;
        if (!is_string($value) || trim($value) === '' || preg_match('/^[A-Za-z0-9_\-]{1,40}$/', trim($value)) !== 1) {
            if ($required || $value !== null) {
                throw HttpException::validation([$name => [$required ? 'Ce paramètre est obligatoire.' : 'Identifiant invalide.']]);
            }

            return null;
        }

        return trim($value);
    }

    /** Réponse 409 avec les deux versions de chaque note en conflit : jamais résolue automatiquement. */
    protected function conflictResponse(VersionConflict $conflict): ResponseInterface
    {
        $grade = Resource::grade();
        $payload = [
            'type' => 'https://api.scholaris.cm/errors/conflict',
            'title' => 'Conflit',
            'status' => 409,
            'detail' => $conflict->getMessage(),
            'conflicts' => array_map(static fn (array $c): array => ['server' => $grade->toArray($c['server']), 'client' => $c['client']], $conflict->conflicts),
        ];

        return $this->responder->json($payload, 409)->withHeader('Content-Type', 'application/problem+json; charset=utf-8');
    }
}
