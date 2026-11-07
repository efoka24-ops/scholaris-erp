<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Census\Http;

use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Core\Security\Principal;

/** Lecture des entrées d'une requête et pagination par curseur (identifiant du dernier élément, 50 par page, 200 au plus). */
final class RequestInput
{
    private function __construct()
    {
    }

    public static function principal(ServerRequestInterface $request): Principal
    {
        $principal = $request->getAttribute('principal');

        return $principal instanceof Principal ? $principal : throw HttpException::unauthorized();
    }

    public static function attribute(ServerRequestInterface $request, string $name): string
    {
        $value = $request->getAttribute($name);

        return is_string($value) && $value !== '' ? $value : throw HttpException::badRequest('Paramètre de route manquant.');
    }

    /** @return array<string, mixed> */
    public static function body(ServerRequestInterface $request): array
    {
        $raw = trim((string) $request->getBody());
        $body = $raw === '' ? [] : json_decode($raw, true);
        if (!is_array($body)) {
            throw HttpException::validation(['body' => ['JSON attendu.']]);
        }

        return $body;
    }

    public static function query(ServerRequestInterface $request, string $name): ?string
    {
        $value = $request->getQueryParams()[$name] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param callable(?string, int): list<array<string, mixed>> $fetch lignes triées par identifiant croissant, après le curseur, au plus $limit
     * @param callable(array<string, mixed>): array<string, mixed> $map
     * @return array{data: list<array<string, mixed>>, meta: array{next_cursor: ?string, per_page: int}}
     */
    public static function page(ServerRequestInterface $request, callable $fetch, callable $map): array
    {
        $query = ListQuery::fromRequest($request);
        $rows = $fetch(ListQuery::decodeCursor($query->cursor), $query->perPage + 1);
        $next = null;
        if (count($rows) > $query->perPage) {
            array_pop($rows);
            $last = end($rows);
            $next = $last === false ? null : ListQuery::encodeCursor((string) $last['id']);
        }

        return ['data' => array_map($map, $rows), 'meta' => ['next_cursor' => $next, 'per_page' => $query->perPage]];
    }
}
