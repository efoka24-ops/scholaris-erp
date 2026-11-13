<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicNursery\Support;

use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\ListQuery;

/**
 * Pagination par curseur opaque (décalage) des listes de la Maternelle publique : `per_page` (50 par défaut, 200 maximum, sinon 422)
 * et `cursor` ; la réponse porte `meta.next_cursor` (null en fin de liste) et `meta.per_page`. Le service lit `limit()` lignes à partir
 * de `offset` (une de plus que la page, pour savoir s'il en reste) ; `envelope()` coupe cette ligne en trop.
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

    /** Nombre de lignes à lire : la page plus une. */
    public function limit(): int
    {
        return $this->perPage + 1;
    }

    /**
     * @param list<array<string, mixed>> $rows lignes lues avec limit() et offset
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
}
