<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Staff\Http\Resources;

/** Ressources du module, une liste blanche par type : jamais tenant_id ni colonne interne. */
final class StaffResources
{
    /** @param list<string> $fields */
    private static function make(array $fields): Resource
    {
        return new class ($fields) extends Resource {
            /** @param list<string> $names */
            public function __construct(private readonly array $names)
            {
            }

            protected function fields(): array
            {
                return $this->names;
            }
        };
    }

    public static function staff(): Resource
    {
        return self::make([
            'id', 'person_id', 'first_name', 'last_name', 'full_name', 'phone', 'email', 'home_establishment_id', 'kind', 'status',
            'grade', 'category', 'employee_number', 'department_id', 'hired_on', 'left_on', 'created_at', 'updated_at',
        ]);
    }

    public static function qualification(): Resource
    {
        return self::make(['id', 'staff_id', 'title', 'institution', 'obtained_on', 'created_at']);
    }

    public static function assignment(): Resource
    {
        return self::make([
            'id', 'staff_id', 'establishment_id', 'academic_year_id', 'structure_id', 'subject_id', 'subject_label', 'session_kind',
            'planned_minutes', 'validated_minutes', 'element_ref', 'group_ref', 'level', 'starts_on', 'ends_on', 'status', 'created_at', 'updated_at',
        ]);
    }

    public static function rate(): Resource
    {
        return self::make(['id', 'establishment_id', 'category', 'level', 'hourly_rate', 'monthly_cap_minutes', 'updated_at']);
    }

    public static function contract(): Resource
    {
        return self::make([
            'id', 'establishment_id', 'staff_id', 'reference', 'starts_on', 'ends_on', 'hourly_rate', 'max_minutes', 'status',
            'approval_id', 'created_at', 'updated_at',
        ]);
    }

    public static function hour(): Resource
    {
        return self::make([
            'id', 'establishment_id', 'staff_id', 'assignment_id', 'contract_id', 'slot_id', 'held_on', 'minutes', 'session_kind', 'level', 'content',
            'status', 'source', 'declared_by', 'teacher_signed_at', 'validated_by', 'countersigned_at', 'rejection_reason',
            'statement_id', 'created_at', 'updated_at',
        ]);
    }

    public static function statement(): Resource
    {
        return self::make([
            'id', 'establishment_id', 'staff_id', 'contract_id', 'period', 'minutes_total', 'hetd_milli', 'hourly_rate', 'gross_amount',
            'status', 'approval_id', 'validated_by', 'validated_at', 'paid_on', 'payment_reference', 'created_at', 'updated_at',
        ]);
    }
}
