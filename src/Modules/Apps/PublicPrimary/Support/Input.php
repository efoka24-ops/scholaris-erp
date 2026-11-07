<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicPrimary\Support;

use DateTimeImmutable;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Core\Security\Principal;

/**
 * Lecture des requêtes de la Primaire publique : personne authentifiée, école (en-tête X-Establishment-Id), corps JSON, paramètres,
 * dates et pagination par curseur (identifiant du dernier élément, 50 par page, 200 au plus : ListQuery du noyau).
 */
final class Input
{
    private function __construct()
    {
    }

    public static function principal(ServerRequestInterface $request): Principal
    {
        $principal = $request->getAttribute('principal');

        return $principal instanceof Principal ? $principal : throw HttpException::unauthorized();
    }

    public static function establishment(ServerRequestInterface $request): string
    {
        return self::attribute($request, 'establishment_id');
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
        if ($raw === '') {
            return [];
        }
        $body = json_decode($raw, true);
        if (!is_array($body) || (array_is_list($body) && $body !== [])) {
            throw HttpException::validation(['body' => ['Objet JSON attendu.']]);
        }

        return $body;
    }

    public static function query(ServerRequestInterface $request, string $name): ?string
    {
        $value = $request->getQueryParams()[$name] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    public static function requiredQuery(ServerRequestInterface $request, string $name, string $message): string
    {
        return self::query($request, $name) ?? throw HttpException::validation([$name => [$message]]);
    }

    public static function isDate(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value;
    }

    public static function isYearLabel(mixed $value): bool
    {
        return is_string($value) && preg_match('/^(\d{4})-(\d{4})$/', $value, $m) === 1 && (int) $m[2] === (int) $m[1] + 1;
    }

    /**
     * Texte facultatif borné ; une erreur est ajoutée si la valeur n'est pas du texte ou dépasse la longueur.
     *
     * @param array<string, list<string>> $errors
     */
    public static function text(mixed $value, int $max, string $field, array &$errors, bool $required = false): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            if ($required) {
                $errors[$field][] = 'Valeur requise.';
            }

            return null;
        }
        if (!is_string($value) || mb_strlen(trim($value)) > $max) {
            $errors[$field][] = 'Texte de ' . $max . ' caractères au plus attendu.';

            return null;
        }

        return trim($value);
    }

    /**
     * Page de liste : `$fetch(après, limite)` rend des lignes triées par `id` croissant ; une ligne de plus que la page signale la suite.
     *
     * @param callable(?string, int): list<array<string, mixed>> $fetch
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

    /**
     * Pagination en mémoire d'une liste déjà bornée (élèves d'une classe…), triée par `id`.
     *
     * @param list<array<string, mixed>> $rows
     * @return array{data: list<array<string, mixed>>, meta: array{next_cursor: ?string, per_page: int}}
     */
    public static function pageOf(ServerRequestInterface $request, array $rows): array
    {
        usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['id'], (string) $b['id']));

        return self::page(
            $request,
            static fn (?string $after, int $limit): array => array_slice(array_values(array_filter($rows, static fn (array $row): bool => $after === null || strcmp((string) $row['id'], $after) > 0)), 0, $limit),
            static fn (array $row): array => $row,
        );
    }
}
