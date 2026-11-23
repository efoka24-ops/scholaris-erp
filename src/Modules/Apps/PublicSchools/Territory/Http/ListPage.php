<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Territory\Http;

use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\ListQuery;

/** Pagination par curseur d'une liste déjà triée : le curseur est l'identifiant d'établissement de la dernière ligne rendue. */
final class ListPage
{
    private function __construct()
    {
    }

    /**
     * @param list<array<string, mixed>> $rows lignes triées, chacune avec `establishment_id`
     * @return array{data: list<array<string, mixed>>, meta: array{next_cursor: ?string, per_page: int}}
     */
    public static function of(ServerRequestInterface $request, array $rows): array
    {
        $query = ListQuery::fromRequest($request);
        $after = ListQuery::decodeCursor($query->cursor);
        if ($after !== null) {
            foreach ($rows as $index => $row) {
                if ($row['establishment_id'] === $after) {
                    $rows = array_slice($rows, $index + 1);
                    break;
                }
            }
        }
        $page = array_slice($rows, 0, $query->perPage);
        $last = end($page);
        $next = count($rows) > $query->perPage && $last !== false ? ListQuery::encodeCursor((string) $last['establishment_id']) : null;

        return ['data' => $page, 'meta' => ['next_cursor' => $next, 'per_page' => $query->perPage]];
    }
}
