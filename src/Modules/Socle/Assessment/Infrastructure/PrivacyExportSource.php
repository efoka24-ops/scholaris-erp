<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Infrastructure;

use Scholaris\Modules\Socle\Audit\Contracts\TableExportSource;

/**
 * Source d'export complet du module Assessment (tenant:export). Liste blanche de colonnes : aucun secret n'est exporté.
 */
final class PrivacyExportSource extends TableExportSource
{
    public function name(): string
    {
        return 'assessment';
    }

    protected function tables(): array
    {
        return [
            'subjects' => self::table('id tenant_id establishment_id code name kind coefficient credits active external_key created_at updated_at'),
            'subject_elements' => self::table('id tenant_id establishment_id subject_id code name coefficient credits active external_key created_at'),
            'assessments' => self::table('id tenant_id establishment_id subject_id element_id structure_id period_id academic_year_id kind title weight max_score grading_mode anonymous status teacher_person_id held_on score_step exam_session created_by created_at updated_at'),
            'grades' => self::table('id tenant_id establishment_id assessment_id person_id score absence qualitative remark version entered_by client_uuid created_at updated_at'),
            'grade_events' => self::table('id tenant_id establishment_id grade_id assessment_id person_id event_kind old_score new_score old_absence new_absence author reason approved_by happened_at'),
            'grade_corrections' => self::table('id tenant_id establishment_id grade_id assessment_id person_id new_score new_absence reason status approval_id requested_by decided_by created_at decided_at approval_step approval_trail'),
            'calculation_rules' => self::table('id tenant_id establishment_id academic_year_id structure_id version label definition created_by created_at'),
            'results' => self::table('id tenant_id establishment_id person_id structure_id period_id exam_session period_label average rank_in_class class_size credits_earned credits_total mention proposal decision rule_version details computed_extras status computed_at published_at'),
            'class_councils' => self::table('id tenant_id establishment_id structure_id period_id period_label status held_on rule_version summary minutes minutes_document_id minutes_status created_by validated_by created_at validated_at'),
            'council_lines' => self::table('id tenant_id establishment_id council_id person_id average rank_in_class unjustified_absences conduct proposal decision reason appraisal'),
            'deliberations' => self::table('id tenant_id establishment_id structure_id period_id exam_session period_label status president_id members_expected members_present quorum_reached rule_version summary minutes minutes_document_id minutes_status minutes_fingerprint minutes_version minutes_history signatures reopen_reason created_by created_at closed_at'),
            'deliberation_lines' => self::table('id tenant_id establishment_id deliberation_id person_id average credits_earned credits_total unit_averages line_extras proposal decision reason'),
            'report_card_requests' => self::table('id tenant_id establishment_id council_id person_id period_id status document_id error_message created_at processed_at'),
        ];
    }
}
