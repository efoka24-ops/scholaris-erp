<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Attendance\Http;

use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Modules\Apps\Higher\Attendance\Infrastructure\AttendanceStore;

/** Liste paginée (curseur = décalage) d'une table hi_* avec filtres en liste blanche et filtre de périmètre déjà calculé. */
final class Listing
{
    private function __construct()
    {
    }

    /**
     * @param list<string> $filters
     * @param array{0: string, 1: list<string>} $scope
     * @param callable(array<string, mixed>): array<string, mixed> $map
     * @return array{data: list<array<string, mixed>>, meta: array{next_cursor: ?string, per_page: int}}
     */
    public static function of(ServerRequestInterface $request, AttendanceStore $store, string $table, string $tenantId, string $establishmentId, array $filters, string $orderBy, array $scope, callable $map): array
    {
        $query = ListQuery::fromRequest($request, $filters);
        $decoded = ListQuery::decodeCursor($query->cursor);
        $offset = $decoded !== null && ctype_digit($decoded) ? (int) $decoded : 0;
        $rows = $store->select($table, $tenantId, $establishmentId, $query->filters, $orderBy, $query->perPage + 1, $scope[0], $scope[1], $offset);
        $next = null;
        if (count($rows) > $query->perPage) {
            array_pop($rows);
            $next = ListQuery::encodeCursor((string) ($offset + $query->perPage));
        }

        return ['data' => array_map($map, $rows), 'meta' => ['next_cursor' => $next, 'per_page' => $query->perPage]];
    }
}
