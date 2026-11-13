<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Teaching\Http;

/** Ressources publiques de l'enseignement : liste blanche par type, entiers et JSON décodés, client et établissement jamais rendus. */
final class TeachingResources
{
    private const FIELDS = [
        'teacher' => ['id', 'person_id', 'staff_id', 'school_id', 'department_id', 'teacher_status', 'grade', 'diplomas', 'origin_establishment', 'public_servant',
            'employer_authorization_ref', 'employer_authorization_document', 'employer_authorization_until', 'created_at', 'updated_at'],
        'room' => ['id', 'school_id', 'code', 'name', 'capacity', 'active', 'created_at', 'updated_at'],
        'assignment' => ['id', 'school_id', 'department_id', 'program_id', 'level_id', 'group_id', 'academic_year_id', 'curriculum_id', 'element_id', 'element_code',
            'element_title', 'person_id', 'staff_id', 'session_kind', 'planned_minutes', 'staff_assignment_id', 'status', 'assigned_by', 'created_at', 'updated_at'],
        'entry' => ['id', 'slot_id', 'assignment_id', 'school_id', 'department_id', 'program_id', 'level_id', 'group_id', 'group_refs', 'element_id', 'element_title',
            'person_id', 'room_id', 'weekday', 'start_time', 'end_time', 'day_period', 'session_kind', 'valid_from', 'valid_to', 'headcount', 'created_at'],
        'change' => ['id', 'entry_id', 'group_id', 'change_kind', 'occurs_on', 'new_date', 'new_start_time', 'new_end_time', 'replacement_person_id', 'new_room_id',
            'reason', 'notified_count', 'created_by', 'created_at'],
        'log' => ['id', 'assignment_id', 'entry_id', 'slot_id', 'school_id', 'department_id', 'program_id', 'level_id', 'group_id', 'element_id', 'person_id',
            'session_kind', 'held_on', 'start_time', 'end_time', 'minutes', 'content', 'homework', 'attendance_session_id', 'vacation_hour_id', 'teacher_signed_at',
            'teacher_signature', 'countersigned_by', 'countersigner_role', 'countersigned_at', 'status', 'validation_id', 'validated_by', 'validated_at', 'created_at', 'updated_at'],
        'delegate' => ['id', 'group_id', 'person_id', 'designated_by', 'created_at'],
        'validation' => ['id', 'school_id', 'department_id', 'person_id', 'period_month', 'log_count', 'minutes_total', 'cm_minutes', 'td_minutes', 'tp_minutes',
            'hetd_milli', 'validated_by', 'validated_at'],
        'exam' => ['id', 'school_id', 'department_id', 'program_id', 'level_id', 'group_id', 'academic_year_id', 'element_id', 'element_title', 'exam_session',
            'exam_date', 'start_time', 'end_time', 'room_id', 'supervisors', 'paper_ref', 'status', 'convocations_count', 'created_at', 'updated_at'],
        'overrun' => ['id', 'assignment_id', 'extra_minutes', 'document_ref', 'reason', 'approved_by', 'approved_at'],
    ];
    private const INTS = ['planned_minutes', 'capacity', 'weekday', 'headcount', 'notified_count', 'minutes', 'log_count', 'minutes_total', 'cm_minutes',
        'td_minutes', 'tp_minutes', 'hetd_milli', 'convocations_count', 'extra_minutes'];
    private const BOOLS = ['active', 'public_servant'];
    private const JSON = ['diplomas', 'group_refs', 'supervisors'];

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
            } elseif (in_array($field, self::BOOLS, true)) {
                $value = (int) $value === 1;
            } elseif (in_array($field, self::JSON, true)) {
                $decoded = json_decode((string) ($value ?? '[]'), true);
                $value = is_array($decoded) ? $decoded : [];
            }
            $out[$field === 'group_refs' ? 'group_ids' : $field] = $value;
        }

        return $out;
    }

    /** @return callable(array<string, mixed>): array<string, mixed> */
    public static function mapper(string $type): callable
    {
        return static fn (array $row): array => self::of($type, $row);
    }
}
