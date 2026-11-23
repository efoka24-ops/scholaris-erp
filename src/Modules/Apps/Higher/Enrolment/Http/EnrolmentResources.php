<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Enrolment\Http;

/** Ressource publique d'une inscription (liste blanche de champs). */
final class EnrolmentResources
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function enrolment(array $row): array
    {
        return [
            'id' => $row['id'], 'person_id' => $row['person_id'], 'student_id' => $row['student_id'], 'matricule' => $row['matricule'] ?? null,
            'enrollment_id' => $row['enrollment_id'], 'application_id' => $row['application_id'], 'academic_year_id' => $row['academic_year_id'],
            'school_id' => $row['school_id'], 'department_id' => $row['department_id'], 'program_id' => $row['program_id'], 'level_id' => $row['level_id'],
            'group_id' => $row['group_id'], 'study_mode' => $row['study_mode'], 'kind' => $row['enrolment_kind'], 'curriculum_id' => $row['curriculum_id'],
            'curriculum_version' => $row['curriculum_version'] === null ? null : (int) $row['curriculum_version'], 'attempt_number' => (int) $row['attempt_number'],
            'derogation_id' => $row['derogation_id'], 'status' => $row['status'], 'status_reason' => $row['status_reason'],
            'financial_hold' => (int) $row['financial_hold'] === 1, 'financial_hold_reason' => $row['financial_hold_reason'],
            'validated_by' => $row['validated_by'], 'validated_at' => $row['validated_at'], 'withdrawn_on' => $row['withdrawn_on'],
            'refund_percent' => $row['refund_percent'] === null ? null : (int) $row['refund_percent'],
            'refund_xaf' => $row['refund_xaf'] === null ? null : (int) $row['refund_xaf'], 'card_document_id' => $row['card_document_id'],
            'form_document_id' => $row['form_document_id'], 'created_at' => $row['created_at'], 'updated_at' => $row['updated_at'],
        ];
    }
}
