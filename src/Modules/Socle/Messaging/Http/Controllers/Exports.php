<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Http\Controllers;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\ExportsList;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Modules\Socle\Messaging\Http\Resources\Resource;

/** Aides d'export CSV / Excel des listes du module : lecture par pages de 200 (5 000 lignes au plus) et réponse fichier. */
final class Exports
{
    use ExportsList;

    public const LIMIT = 5000;

    /**
     * Toutes les lignes d'une liste avec les filtres de la requête.
     *
     * @param list<string> $filters filtres autorisés
     * @param callable(ListQuery): array{0: list<array<string, mixed>>, 1: ?string} $page
     * @return list<array<string, mixed>>
     */
    public static function collect(ServerRequestInterface $request, array $filters, callable $page): array
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
        } while ($cursor !== null && count($rows) < self::LIMIT);

        return array_slice($rows, 0, self::LIMIT);
    }

    /**
     * @param array<string, string> $columns champ de la ressource => en-tête
     * @param list<array<string, mixed>> $rows
     */
    public static function respond(ServerRequestInterface $request, string $filename, Resource $resource, array $columns, array $rows): ResponseInterface
    {
        $fields = array_keys($columns);

        return (new self())->csvResponse(
            new Psr17Factory(),
            new Psr17Factory(),
            $filename,
            array_values($columns),
            array_map(static function (array $row) use ($resource, $fields): array {
                $r = $resource->toArray($row);

                return array_map(static fn (string $field): mixed => is_array($r[$field]) ? json_encode($r[$field], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $r[$field], $fields);
            }, $rows),
            $request,
        );
    }
}
