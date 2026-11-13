<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Admissions\Http;

use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\ListQuery;

/** Ressources publiques (listes blanches de champs) et pagination par curseur opaque des admissions. */
final class AdmissionsResources
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function campaign(array $row): array
    {
        return [
            'id' => $row['id'], 'school_id' => $row['school_id'], 'department_id' => $row['department_id'], 'program_id' => $row['program_id'],
            'level_id' => $row['level_id'], 'academic_year_id' => $row['academic_year_id'], 'title' => $row['title'], 'opens_on' => $row['opens_on'],
            'closes_on' => $row['closes_on'], 'seats' => (int) $row['seats'], 'application_fee_xaf' => (int) $row['application_fee_xaf'],
            'required_documents' => self::json($row['required_documents_json']), 'prerequisites' => self::json($row['prerequisites_json']),
            'selection_mode' => $row['selection_mode'], 'jury' => $row['jury_json'] === null ? null : self::json($row['jury_json']),
            'status' => $row['status'], 'cancelled_reason' => $row['cancelled_reason'], 'created_at' => $row['created_at'], 'updated_at' => $row['updated_at'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function application(array $row): array
    {
        $resource = [
            'id' => $row['id'], 'reference' => $row['reference'], 'campaign_id' => $row['campaign_id'], 'person_id' => $row['person_id'],
            'school_id' => $row['school_id'], 'department_id' => $row['department_id'], 'program_id' => $row['program_id'], 'level_id' => $row['level_id'],
            'academic_year_id' => $row['academic_year_id'], 'channel' => $row['channel'], 'application_kind' => $row['application_kind'],
            'origin_institution' => $row['origin_institution'], 'diploma' => $row['diploma'], 'diploma_series' => $row['diploma_series'],
            'diploma_year' => $row['diploma_year'] === null ? null : (int) $row['diploma_year'], 'diploma_mention' => $row['diploma_mention'],
            'status' => $row['status'], 'fee' => [
                'status' => $row['fee_status'], 'invoice_id' => $row['fee_invoice_id'], 'payment_id' => $row['fee_payment_id'],
                'receipt_number' => $row['fee_receipt_number'], 'refundable' => (int) $row['fee_refund_due'] === 1,
            ],
            'score_hundredths' => $row['score_hundredths'] === null ? null : (int) $row['score_hundredths'],
            'rank_position' => $row['rank_position'] === null ? null : (int) $row['rank_position'], 'list_kind' => $row['list_kind'],
            'decision_reason' => $row['decision_reason'], 'conditional_until' => $row['conditional_until'], 'over_capacity' => (int) $row['over_capacity'] === 1,
            'decided_by' => $row['decided_by'], 'decided_at' => $row['decided_at'], 'acknowledgement_document_id' => $row['acknowledgement_document_id'],
            'letter_document_id' => $row['letter_document_id'], 'enrolment_id' => $row['enrolment_id'], 'submitted_at' => $row['submitted_at'],
            'completed_at' => $row['completed_at'], 'created_at' => $row['created_at'],
        ];
        if (isset($row['documents']) && is_array($row['documents'])) {
            $resource['documents'] = array_map(static fn (array $d): array => [
                'id' => $d['id'], 'requirement_code' => $d['requirement_code'], 'label' => $d['label'], 'blocking' => (int) $d['blocking'] === 1,
                'proves_prerequisite' => (int) $d['proves_prerequisite'] === 1, 'document_ref' => $d['document_ref'], 'status' => $d['status'],
                'rejection_reason' => $d['rejection_reason'], 'verified_by' => $d['verified_by'], 'verified_at' => $d['verified_at'],
            ], $row['documents']);
        }
        if (isset($row['decisions']) && is_array($row['decisions'])) {
            $resource['decisions'] = array_map(static fn (array $d): array => [
                'id' => $d['id'], 'decision' => $d['decision'], 'reason' => $d['reason'], 'over_capacity' => (int) $d['over_capacity'] === 1,
                'over_capacity_reason' => $d['over_capacity_reason'], 'conditional_until' => $d['conditional_until'], 'source' => $d['decision_source'],
                'decided_by' => $d['decided_by'], 'decided_at' => $d['decided_at'],
            ], $row['decisions']);
        }
        if (isset($row['equivalences']) && is_array($row['equivalences'])) {
            $resource['equivalences'] = array_map(static fn (array $e): array => [
                'origin_unit_code' => $e['origin_unit_code'], 'origin_unit_title' => $e['origin_unit_title'], 'origin_credits' => (int) $e['origin_credits'],
                'origin_grade_hundredths' => $e['origin_grade_hundredths'] === null ? null : (int) $e['origin_grade_hundredths'],
                'unit_code' => $e['unit_code'], 'granted' => (int) $e['granted'] === 1, 'commission_reference' => $e['commission_reference'],
            ], $row['equivalences']);
        }

        return $resource;
    }

    /**
     * @param list<string> $filters
     * @param list<string> $sorts
     * @return array{0: ListQuery, 1: int} requête de liste et position du curseur
     */
    public static function listQuery(ServerRequestInterface $request, array $filters, array $sorts = []): array
    {
        $query = ListQuery::fromRequest($request, $filters, $sorts);
        $decoded = ListQuery::decodeCursor($query->cursor);

        return [$query, $decoded !== null && ctype_digit($decoded) ? (int) $decoded : 0];
    }

    /**
     * @param list<array<string, mixed>> $rows lignes lues avec une de plus que la page
     * @param \Closure(array<string, mixed>): array<string, mixed> $map
     * @return array{data: list<array<string, mixed>>, meta: array{next_cursor: ?string, per_page: int}}
     */
    public static function page(array $rows, int $perPage, int $offset, \Closure $map): array
    {
        $next = null;
        if (count($rows) > $perPage) {
            array_pop($rows);
            $next = ListQuery::encodeCursor((string) ($offset + $perPage));
        }

        return ['data' => array_map($map, $rows), 'meta' => ['next_cursor' => $next, 'per_page' => $perPage]];
    }

    /** @return array<mixed> */
    private static function json(mixed $value): array
    {
        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
