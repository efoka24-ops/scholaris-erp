<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Scheduling\Http\Resources;

/** Sorties à liste blanche : jamais tenant_id ni colonne interne. */
final class SchedulingResources
{
    private static function make(string ...$fields): Resource
    {
        return new class (array_values($fields)) extends Resource {
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

    public static function event(): Resource
    {
        return self::make('id', 'establishment_id', 'kind', 'title', 'starts_on', 'ends_on', 'academic_year_id', 'structure_id', 'notes', 'created_at', 'updated_at');
    }

    public static function slot(): Resource
    {
        return self::make('id', 'establishment_id', 'subject_label', 'teacher_person_id', 'room_id', 'course_unit_id', 'session_kind', 'structure_ids', 'weekday', 'start_time', 'end_time', 'valid_from', 'valid_to', 'period_of_day', 'created_at', 'updated_at');
    }

    public static function change(): Resource
    {
        return self::make('id', 'establishment_id', 'slot_id', 'on_date', 'kind', 'new_date', 'new_start_time', 'new_end_time', 'new_room_id', 'new_teacher_person_id', 'reason', 'notified_count', 'notified_at', 'created_at');
    }
}
