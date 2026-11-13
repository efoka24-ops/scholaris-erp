<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Attendance\Http\Resources;

/** Sortie d'une ressource à liste blanche : jamais tenant_id, empreinte de jeton ni colonne interne. */
final class Resource
{
    /** @param list<string> $fields champs exposés, dans l'ordre */
    private function __construct(private readonly array $fields)
    {
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function toArray(array $row): array
    {
        $out = [];
        foreach ($this->fields as $field) {
            $out[$field] = $row[$field] ?? null;
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public function collection(array $rows): array
    {
        return array_map($this->toArray(...), $rows);
    }

    /**
     * Sous-liste d'une valeur de type inconnu (lignes imbriquées d'un agrégat) : les éléments qui ne sont pas des lignes sont ignorés.
     *
     * @return list<array<string, mixed>>
     */
    public function nested(mixed $rows): array
    {
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row)) {
                $out[] = $this->toArray($row);
            }
        }

        return $out;
    }

    public static function session(): self
    {
        return new self(['id', 'establishment_id', 'slot_id', 'structure_id', 'teacher_person_id', 'subject_label', 'session_date', 'start_time', 'end_time', 'status', 'client_uuid', 'expected_count', 'taken_by', 'validated_by', 'validated_at', 'synced_at', 'created_at', 'updated_at']);
    }

    /**
     * @param array<string, mixed> $session séance avec ses lignes et compteurs
     * @return array<string, mixed>
     */
    public static function sessionDetail(array $session): array
    {
        return self::session()->toArray($session) + [
            'present_count' => $session['present_count'] ?? 0,
            'absent_count' => $session['absent_count'] ?? 0,
            'late_count' => $session['late_count'] ?? 0,
            'recorded_count' => $session['recorded_count'] ?? 0,
            'records' => self::record()->nested($session['records'] ?? null),
        ];
    }

    public static function record(): self
    {
        return new self(['id', 'session_id', 'person_id', 'session_date', 'status', 'arrived_at', 'client_uuid', 'justified', 'justification_id', 'note', 'created_at', 'updated_at']);
    }

    public static function justification(): self
    {
        return new self(['id', 'establishment_id', 'person_id', 'absence_from', 'absence_to', 'reason', 'document_ref', 'status', 'submitted_by', 'decided_by', 'decided_at', 'decision_note', 'absences_justified', 'created_at', 'updated_at']);
    }

    public static function incident(): self
    {
        return new self(['id', 'establishment_id', 'person_id', 'occurred_on', 'category', 'description', 'reported_by', 'created_at']);
    }

    public static function sanction(): self
    {
        return new self(['id', 'establishment_id', 'incident_id', 'person_id', 'kind', 'reason', 'status', 'approval_id', 'proposed_by', 'decided_by', 'decided_at', 'created_at', 'updated_at']);
    }

    public static function pickup(): self
    {
        return new self(['id', 'establishment_id', 'learner_id', 'pickup_name', 'relationship', 'phone', 'photo_document_ref', 'grant_type', 'valid_on', 'granted_by', 'status', 'created_at', 'updated_at']);
    }
}
