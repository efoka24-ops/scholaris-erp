<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\NationalExams\Http;

use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Modules\Apps\Higher\NationalExams\Infrastructure\NationalExamsStore;

/** Ressources publiques des examens nationaux : liste blanche par type, types convertis, client et établissement jamais rendus. */
final class NationalResources
{
    private const FIELDS = [
        'session' => ['id', 'school_id', 'department_id', 'program_id', 'level_id', 'academic_year_id', 'exam_type', 'specialty_label', 'calendar_ref',
            'registration_deadline', 'exam_starts_on', 'attendance_from', 'attendance_to', 'fee_xaf', 'required_pieces', 'status', 'export_count',
            'last_exported_at', 'last_exported_by', 'export_fingerprint', 'created_at', 'updated_at'],
        'candidate' => ['id', 'session_id', 'enrolment_id', 'person_id', 'matricule', 'school_id', 'program_id', 'level_id', 'group_id', 'status',
            'presence_percent', 'attendance_ok', 'fee_receipt', 'pieces_received', 'candidate_number', 'exam_centre', 'convened_at', 'result_code',
            'result_mark', 'result_imported_at', 'withdrawn_reason', 'created_at', 'updated_at'],
        'event' => ['id', 'session_id', 'candidate_id', 'event_kind', 'after_export', 'detail', 'actor_id', 'alerted_count', 'created_at'],
    ];
    private const INTS = ['fee_xaf', 'export_count', 'alerted_count'];
    private const FLOATS = ['presence_percent', 'result_mark'];
    private const BOOLS = ['attendance_ok', 'after_export'];
    private const JSON = ['required_pieces', 'pieces_received', 'detail'];

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
            if ($value !== null && in_array($field, self::INTS, true)) {
                $value = (int) $value;
            } elseif ($value !== null && in_array($field, self::FLOATS, true)) {
                $value = (float) $value;
            } elseif (in_array($field, self::BOOLS, true)) {
                $value = (int) $value === 1;
            } elseif (in_array($field, self::JSON, true)) {
                $decoded = json_decode((string) ($value ?? '[]'), true);
                $value = is_array($decoded) ? $decoded : [];
            }
            $out[$field] = $value;
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

    /**
     * Liste paginée (curseur = décalage) avec filtres en liste blanche et filtre de périmètre déjà calculé.
     *
     * @param list<string> $filters
     * @param array<string, scalar|null> $where
     * @param array{0: string, 1: list<string>} $scope
     * @return array{data: list<array<string, mixed>>, meta: array{next_cursor: ?string, per_page: int}}
     */
    public static function listing(ServerRequestInterface $request, NationalExamsStore $store, string $type, string $table, string $tenantId, string $establishmentId, array $filters, array $where, string $orderBy, array $scope): array
    {
        $query = ListQuery::fromRequest($request, $filters);
        $decoded = ListQuery::decodeCursor($query->cursor);
        $offset = $decoded !== null && ctype_digit($decoded) ? (int) $decoded : 0;
        $rows = $store->select($table, $tenantId, $establishmentId, $where + $query->filters, $orderBy, $query->perPage + 1, $scope[0], $scope[1], $offset);
        $next = null;
        if (count($rows) > $query->perPage) {
            array_pop($rows);
            $next = ListQuery::encodeCursor((string) ($offset + $query->perPage));
        }

        return ['data' => array_map(static fn (array $row): array => self::of($type, $row), $rows), 'meta' => ['next_cursor' => $next, 'per_page' => $query->perPage]];
    }
}
