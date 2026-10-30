<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Staff\Infrastructure;

use Scholaris\Modules\Socle\Audit\Contracts\TableExportSource;

/**
 * Source d'export complet du module Staff (tenant:export). Liste blanche de colonnes : aucun secret n'est exporté.
 */
final class PrivacyExportSource extends TableExportSource
{
    public function name(): string
    {
        return 'staff';
    }

    protected function tables(): array
    {
        return [
            'staff_members' => self::table('id tenant_id person_id home_establishment_id kind status grade category employee_number department_id hired_on left_on created_at updated_at'),
            'staff_qualifications' => self::table('id tenant_id staff_id title institution obtained_on created_at'),
            'staff_assignments' => self::table('id tenant_id establishment_id staff_id academic_year_id structure_id subject_id subject_label session_kind planned_minutes element_ref group_ref level starts_on ends_on status created_by created_at updated_at'),
            'vacation_contracts' => self::table('id tenant_id establishment_id staff_id reference starts_on ends_on hourly_rate max_minutes status approval_id created_by created_at updated_at'),
            'vacation_hours' => self::table('id tenant_id establishment_id staff_id assignment_id contract_id slot_id held_on minutes session_kind level content status source declared_by teacher_signed_at validated_by countersigned_at rejection_reason statement_id created_at updated_at'),
            'vacation_rates' => self::table('id tenant_id establishment_id category level hourly_rate monthly_cap_minutes updated_at'),
            'vacation_statements' => self::table('id tenant_id establishment_id staff_id contract_id period minutes_total hetd_milli hourly_rate gross_amount status approval_id validated_by validated_at paid_on payment_reference created_by created_at updated_at'),
            'leave_balances' => self::table('id tenant_id establishment_id staff_id leave_type leave_year entitled_days updated_at'),
            'leave_requests' => self::table('id tenant_id establishment_id staff_id leave_type starts_on ends_on days reason status approval_id requested_by decided_by decided_at created_at updated_at'),
        ];
    }
}
