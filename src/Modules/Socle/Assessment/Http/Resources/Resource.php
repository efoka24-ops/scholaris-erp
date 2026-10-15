<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Http\Resources;

/**
 * Sortie d'une ressource à liste blanche : jamais tenant_id ni colonne interne. Un champ peut porter un type
 * (« weight:float », « active:bool », « details:json ») pour un rendu identique sur MySQL (chaînes) et SQLite (nombres).
 */
final class Resource
{
    /** @param list<string> $fields */
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
        foreach ($this->fields as $definition) {
            [$field, $type] = array_pad(explode(':', $definition, 2), 2, '');
            $value = $row[$field] ?? null;
            if ($value !== null) {
                $value = match ($type) {
                    'float' => (float) $value,
                    'int' => (int) $value,
                    'bool' => (int) $value === 1,
                    'json' => is_string($value) ? json_decode($value, true) : $value,
                    default => $value,
                };
            }
            $out[$field] = $value;
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
     * @param array<string, mixed> $row
     * @param array<string, self> $children clé du tableau enfant => ressource
     * @return array<string, mixed>
     */
    public function with(array $row, array $children): array
    {
        $out = $this->toArray($row);
        foreach ($children as $key => $resource) {
            $out[$key] = $resource->collection(array_values((array) ($row[$key] ?? [])));
        }

        return $out;
    }

    public static function subject(): self
    {
        return new self(['id', 'establishment_id', 'code', 'name', 'kind', 'coefficient:float', 'credits:int', 'active:bool', 'external_key', 'created_at', 'updated_at']);
    }

    public static function element(): self
    {
        return new self(['id', 'subject_id', 'code', 'name', 'coefficient:float', 'credits:int', 'active:bool', 'external_key']);
    }

    public static function assessment(): self
    {
        return new self(['id', 'establishment_id', 'subject_id', 'element_id', 'structure_id', 'period_id', 'academic_year_id', 'kind', 'title', 'weight:float', 'max_score:float', 'grading_mode', 'anonymous:bool', 'status', 'teacher_person_id', 'held_on', 'score_step:float', 'exam_session', 'created_at', 'updated_at']);
    }

    public static function grade(): self
    {
        return new self(['id', 'assessment_id', 'person_id', 'score:float', 'absence', 'qualitative', 'remark', 'version:int', 'entered_by', 'client_uuid', 'created_at', 'updated_at']);
    }

    public static function event(): self
    {
        return new self(['id', 'grade_id', 'assessment_id', 'person_id', 'event_kind', 'old_score:float', 'new_score:float', 'old_absence', 'new_absence', 'author', 'reason', 'approved_by', 'happened_at']);
    }

    public static function correction(): self
    {
        return new self(['id', 'establishment_id', 'grade_id', 'assessment_id', 'person_id', 'new_score:float', 'new_absence', 'reason', 'status', 'requested_by', 'decided_by', 'created_at', 'decided_at', 'approval_step:int', 'steps_total:int', 'current_step', 'approval_trail:json']);
    }

    public static function rules(): self
    {
        return new self(['id', 'establishment_id', 'academic_year_id', 'structure_id', 'version:int', 'label', 'definition:json', 'created_by', 'created_at', 'effective']);
    }

    /** Résultat vu par l'équipe pédagogique. */
    public static function result(): self
    {
        return new self(['id', 'establishment_id', 'person_id', 'structure_id', 'period_id', 'exam_session', 'period_label', 'average:float', 'rank_in_class:int', 'class_size:int', 'credits_earned:int', 'credits_total:int', 'mention', 'proposal', 'decision', 'rule_version:int', 'details:json', 'computed_extras:json', 'status', 'computed_at', 'published_at']);
    }

    /** Résultat publié, tel que les familles le voient : ni proposition de calcul ni horodatage interne. */
    public static function familyResult(): self
    {
        return new self(['id', 'person_id', 'period_id', 'exam_session', 'period_label', 'average:float', 'rank_in_class:int', 'class_size:int', 'credits_earned:int', 'credits_total:int', 'mention', 'decision', 'details:json', 'computed_extras:json', 'published_at']);
    }

    public static function council(): self
    {
        return new self(['id', 'establishment_id', 'structure_id', 'period_id', 'period_label', 'status', 'held_on', 'rule_version:int', 'summary:json', 'minutes', 'minutes_document_id', 'minutes_status', 'created_by', 'validated_by', 'created_at', 'validated_at']);
    }

    public static function councilLine(): self
    {
        return new self(['id', 'person_id', 'name', 'matricule', 'average:float', 'rank_in_class:int', 'unjustified_absences:int', 'conduct', 'proposal', 'decision', 'reason', 'appraisal']);
    }

    public static function deliberation(): self
    {
        return new self(['id', 'establishment_id', 'structure_id', 'period_id', 'exam_session', 'period_label', 'status', 'president_id', 'members_expected:int', 'members_present', 'quorum_reached:bool', 'rule_version:int', 'summary', 'minutes', 'minutes_document_id', 'minutes_status', 'minutes_fingerprint', 'minutes_version:int', 'minutes_history', 'decisions', 'decision_counts', 'signatures', 'reopen_reason', 'created_by', 'created_at', 'closed_at']);
    }

    public static function deliberationLine(): self
    {
        return new self(['id', 'person_id', 'name', 'matricule', 'average:float', 'credits_earned:int', 'credits_total:int', 'unit_averages', 'line_extras', 'proposal', 'decision', 'reason']);
    }

    public static function reportCard(): self
    {
        return new self(['id', 'council_id', 'person_id', 'period_id', 'status', 'document_id', 'error_message', 'created_at', 'processed_at']);
    }
}
