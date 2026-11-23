<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Tutelage\Http;

use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Modules\Apps\Higher\Tutelage\Infrastructure\TutelageStore;

/** Ressources publiques de la tutelle : liste blanche par type, entiers convertis, client et établissement jamais rendus. */
final class TutelageResources
{
    private const FIELDS = [
        'transmission' => ['id', 'school_id', 'program_id', 'level_id', 'academic_year_id', 'period_id', 'tutelage_id', 'university_name', 'transmission_kind',
            'status', 'items_count', 'fingerprint', 'file_name', 'note', 'sent_by', 'sent_at', 'answered_at', 'answered_by'],
        'diploma_file' => ['id', 'school_id', 'program_id', 'level_id', 'academic_year_id', 'person_id', 'enrolment_id', 'tutelage_id', 'university_name',
            'decision_code', 'status', 'status_note', 'transmitted_at', 'status_changed_at', 'created_by'],
        'consultation' => ['id', 'user_id', 'program_id', 'academic_year_id', 'resource_kind', 'rows_count', 'created_at'],
    ];
    private const INTS = ['items_count', 'rows_count'];

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function of(string $type, array $row): array
    {
        $out = [];
        foreach (self::FIELDS[$type] as $field) {
            $value = $row[$field] ?? null;
            $out[$field] = $value !== null && in_array($field, self::INTS, true) ? (int) $value : $value;
        }

        return $out;
    }

    public static function arg(ServerRequestInterface $request, string $name): string
    {
        $value = $request->getAttribute($name);
        if (!is_string($value) || $value === '') {
            throw HttpException::notFound();
        }

        return $value;
    }

    public static function query(ServerRequestInterface $request, string $name): string
    {
        $value = $request->getQueryParams()[$name] ?? null;
        if (!is_string($value) || $value === '') {
            throw HttpException::validation([$name => ['Paramètre obligatoire.']]);
        }

        return $value;
    }

    /**
     * @param list<string> $filters
     * @param array{0: string, 1: list<string>} $scope
     * @return array{data: list<array<string, mixed>>, meta: array{next_cursor: ?string, per_page: int}}
     */
    public static function listing(ServerRequestInterface $request, TutelageStore $store, string $type, string $table, string $tenantId, string $establishmentId, array $filters, string $orderBy, array $scope): array
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

        return ['data' => array_map(static fn (array $row): array => self::of($type, $row), $rows), 'meta' => ['next_cursor' => $next, 'per_page' => $query->perPage]];
    }
}
