<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Support;

use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\ListQuery;

/**
 * Pagination par curseur opaque (décalage) de toutes les listes : `per_page` (50 par défaut, 200 au plus, sinon 422) et `cursor` ;
 * la réponse porte `meta.next_cursor` (null en fin de liste) et `meta.per_page`.
 */
final class Paging
{
    private function __construct(public readonly int $perPage, public readonly int $offset)
    {
    }

    public static function fromRequest(ServerRequestInterface $request): self
    {
        $query = ListQuery::fromRequest($request);
        $decoded = ListQuery::decodeCursor($query->cursor);

        return new self($query->perPage, $decoded !== null && ctype_digit($decoded) ? (int) $decoded : 0);
    }

    /** Nombre de lignes à lire : la page plus une (pour savoir s'il en reste). */
    public function limit(): int
    {
        return $this->perPage + 1;
    }

    /**
     * @param list<array<string, mixed>> $rows lignes lues avec limit() à partir de offset
     * @return array{data: list<array<string, mixed>>, meta: array{next_cursor: ?string, per_page: int}}
     */
    public function envelope(array $rows): array
    {
        $next = null;
        if (count($rows) > $this->perPage) {
            $rows = array_slice($rows, 0, $this->perPage);
            $next = ListQuery::encodeCursor((string) ($this->offset + $this->perPage));
        }

        return ['data' => $rows, 'meta' => ['next_cursor' => $next, 'per_page' => $this->perPage]];
    }

    /**
     * Découpe une liste déjà en mémoire.
     *
     * @param list<array<string, mixed>> $all
     * @return array{data: list<array<string, mixed>>, meta: array{next_cursor: ?string, per_page: int}}
     */
    public function slice(array $all): array
    {
        return $this->envelope(array_slice($all, $this->offset, $this->limit()));
    }
}
