<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Inspection\Http;

use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Core\Security\Principal;

/** Lecture des requêtes de l'inspection : personne, école, corps JSON, paramètres, pagination en mémoire par curseur. */
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

        return is_string($value) && $value !== '' ? $value : throw HttpException::badRequest('Paramètre manquant : ' . $name . '.');
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
     * @param list<array<string, mixed>> $rows lignes portant un `id`
     * @return array{data: list<array<string, mixed>>, meta: array{next_cursor: ?string, per_page: int}}
     */
    public static function pageOf(ServerRequestInterface $request, array $rows): array
    {
        $query = ListQuery::fromRequest($request);
        $after = ListQuery::decodeCursor($query->cursor);
        usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['id'], (string) $b['id']));
        $rows = array_values(array_filter($rows, static fn (array $row): bool => $after === null || strcmp((string) $row['id'], $after) > 0));
        $next = null;
        if (count($rows) > $query->perPage) {
            $rows = array_slice($rows, 0, $query->perPage);
            $next = ListQuery::encodeCursor((string) $rows[count($rows) - 1]['id']);
        }

        return ['data' => $rows, 'meta' => ['next_cursor' => $next, 'per_page' => $query->perPage]];
    }
}
