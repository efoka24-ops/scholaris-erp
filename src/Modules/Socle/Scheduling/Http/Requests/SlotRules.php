<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Scheduling\Http\Requests;

use Closure;
use Scholaris\Modules\Socle\Scheduling\Contracts\SessionKinds;

/** Règles de validation d'un créneau, communes à l'API et au contrat TimetableWriter. */
final class SlotRules
{
    public const REQUIRED = ['subject_label', 'teacher_person_id', 'structure_ids', 'weekday', 'start_time', 'end_time', 'valid_from'];
    public const NULLABLE = ['room_id', 'course_unit_id', 'session_kind', 'valid_to'];

    /** @return array<string, Closure(mixed): mixed> */
    public static function rules(): array
    {
        return [
            'subject_label' => Input::text(120),
            'teacher_person_id' => Input::ref(),
            'room_id' => Input::ref(),
            'course_unit_id' => Input::ref(),
            'session_kind' => Input::enum(SessionKinds::ALL),
            'structure_ids' => Input::refList(),
            'weekday' => Input::weekday(),
            'start_time' => Input::time(),
            'end_time' => Input::time(),
            'valid_from' => Input::date(),
            'valid_to' => Input::date(),
        ];
    }
}
