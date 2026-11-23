<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Support;

use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\ListQuery;

/**
 * Liste paginée d'une table pri_* : filtres `filter[champ]` et tri `sort` en liste blanche (ListQuery), curseur opaque (position),
 * 50 par page par défaut, filtre de périmètre éventuel imposé par le contrôleur.
 */
final class Listing
{
    public function __construct(private readonly Store $store)
    {
    }

    /**
     * @param list<string> $filters colonnes filtrables (égalité)
     * @param list<string> $sorts colonnes triables
     * @param \Closure(array<string, mixed>): array<string, mixed> $map ligne => ressource publique
     * @param array<string, scalar|null> $fixed égalités imposées en plus des filtres
     * @param array{0: string, 1: list<scalar|null>} $scope condition SQL de périmètre et ses paramètres
     * @return array{data: list<array<string, mixed>>, meta: array{next_cursor: ?string, per_page: int}}
     */
    public function page(
        ServerRequestInterface $request,
        string $table,
        string $tenantId,
        string $establishmentId,
        array $filters,
        array $sorts,
        string $defaultSort,
        \Closure $map,
        array $fixed = [],
        array $scope = ['1 = 1', []],
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
        $rows = $this->store->select($table, $tenantId, $establishmentId, $fixed + $query->filters, implode(', ', array_unique($order)), $query->perPage + 1, $scope[0], $scope[1], $offset);
        $next = null;
        if (count($rows) > $query->perPage) {
            array_pop($rows);
            $next = ListQuery::encodeCursor((string) ($offset + $query->perPage));
        }

        return ['data' => array_map($map, $rows), 'meta' => ['next_cursor' => $next, 'per_page' => $query->perPage]];
    }
}
