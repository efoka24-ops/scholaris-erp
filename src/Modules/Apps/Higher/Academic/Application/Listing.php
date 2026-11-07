<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Academic\Application;

use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Modules\Apps\Higher\Academic\Infrastructure\Store;
use Scholaris\Modules\Apps\Higher\Support\Perimeter;

/**
 * Liste paginée d'une table hi_* (Academic, Curriculum) : filtres `filter[champ]` et tri `sort` en liste blanche (ListQuery),
 * filtre de périmètre imposé (ScopedBySchool), curseur opaque (position), 50 par page par défaut.
 */
final class Listing
{
    public function __construct(
        private readonly Store $store,
        private readonly Access $access,
    ) {
    }

    /**
     * @param array<string, string> $scopeColumns clé de périmètre => colonne
     * @param list<string> $filters colonnes filtrables (égalité)
     * @param list<string> $sorts colonnes triables
     * @param \Closure(array<string, mixed>): array<string, mixed> $map ligne => ressource publique
     * @return array{data: list<array<string, mixed>>, meta: array{next_cursor: ?string, per_page: int}}
     */
    public function page(
        ServerRequestInterface $request,
        string $table,
        string $tenantId,
        string $establishmentId,
        Perimeter $perimeter,
        array $scopeColumns,
        array $filters,
        array $sorts,
        string $defaultSort,
        \Closure $map,
    ): array {
        $query = ListQuery::fromRequest($request, $filters, $sorts);
        $offset = 0;
        $decoded = ListQuery::decodeCursor($query->cursor);
        if ($decoded !== null && ctype_digit($decoded)) {
            $offset = (int) $decoded;
        }
        $order = [];
        foreach ($query->sorts as [$field, $direction]) {
            $order[] = $field . ' ' . strtoupper($direction);
        }
        $order[] = $defaultSort;
        $order[] = 'id';
        [$scopeSql, $scopeParams] = $this->access->sqlFilter($perimeter, $scopeColumns);
        $rows = $this->store->select($table, $tenantId, $establishmentId, $query->filters, implode(', ', array_unique($order)), $query->perPage + 1, $scopeSql, $scopeParams, $offset);
        $next = null;
        if (count($rows) > $query->perPage) {
            array_pop($rows);
            $next = ListQuery::encodeCursor((string) ($offset + $query->perPage));
        }

        return ['data' => array_map($map, $rows), 'meta' => ['next_cursor' => $next, 'per_page' => $query->perPage]];
    }
}
