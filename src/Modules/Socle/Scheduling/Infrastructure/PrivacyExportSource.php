<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Scheduling\Infrastructure;

use Scholaris\Modules\Socle\Audit\Contracts\TableExportSource;

/**
 * Source d'export complet du module Scheduling (tenant:export). Liste blanche de colonnes : aucun secret n'est exporté.
 */
final class PrivacyExportSource extends TableExportSource
{
    public function name(): string
    {
        return 'scheduling';
    }

    protected function tables(): array
    {
        return [
            'calendar_events' => self::table('id tenant_id establishment_id kind title starts_on ends_on academic_year_id structure_id notes created_by created_at updated_at'),
            'timetable_slots' => self::table('id tenant_id establishment_id subject_label teacher_person_id room_id course_unit_id session_kind weekday start_time end_time valid_from valid_to period_of_day created_by created_at updated_at'),
            'timetable_slot_structures' => self::table('id tenant_id establishment_id slot_id structure_id'),
            'timetable_changes' => self::table('id tenant_id establishment_id slot_id on_date kind new_date new_start_time new_end_time new_room_id new_teacher_person_id reason notified_count notified_at created_by created_at'),
        ];
    }
}
