<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Attendance\Infrastructure;

use Scholaris\Modules\Socle\Audit\Contracts\TableExportSource;

/**
 * Source d'export complet du module Attendance (tenant:export). Liste blanche de colonnes : aucun secret n'est exporté.
 */
final class PrivacyExportSource extends TableExportSource
{
    public function name(): string
    {
        return 'attendance';
    }

    protected function tables(): array
    {
        return [
            'attendance_sessions' => self::table('id tenant_id establishment_id slot_id structure_id course_unit_id teacher_person_id subject_label session_date start_time end_time status client_uuid expected_count taken_by validated_by validated_at synced_at qr_expires_at created_at updated_at'),
            'attendance_records' => self::table('id tenant_id establishment_id session_id person_id session_date status arrived_at client_uuid justified justification_id note created_at updated_at'),
            'absence_justifications' => self::table('id tenant_id establishment_id person_id absence_from absence_to reason document_ref status submitted_by decided_by decided_at decision_note created_at updated_at'),
            'absence_notifications' => self::table('id tenant_id establishment_id person_id absence_date session_id status recipients created_at'),
            'authorized_pickups' => self::table('id tenant_id establishment_id learner_id pickup_name relationship phone photo_document_ref grant_type valid_on granted_by status created_at updated_at'),
            'incidents' => self::table('id tenant_id establishment_id person_id occurred_on category description reported_by created_at'),
            'sanctions' => self::table('id tenant_id establishment_id incident_id person_id kind reason status approval_id proposed_by decided_by decided_at created_at updated_at'),
        ];
    }
}
