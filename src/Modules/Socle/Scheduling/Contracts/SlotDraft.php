<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Scheduling\Contracts;

/** Créneau hebdomadaire à poser par une application (TimetableWriter). Mêmes règles que POST /timetable-slots. */
final class SlotDraft
{
    /** @param list<string> $structureIds groupes ou classes concernés (cours mutualisé si plusieurs) */
    public function __construct(
        public readonly string $subjectLabel,
        public readonly string $teacherPersonId,
        public readonly array $structureIds,
        /** 1 = lundi … 7 = dimanche */
        public readonly int $weekday,
        /** HH:MM */
        public readonly string $startTime,
        public readonly string $endTime,
        /** AAAA-MM-JJ */
        public readonly string $validFrom,
        public readonly ?string $validTo = null,
        public readonly ?string $roomId = null,
        public readonly ?string $courseUnitId = null,
        /** SessionKinds::* ou null */
        public readonly ?string $sessionKind = null,
    ) {
    }

    /** @return array<string, mixed> champs au format de l'API */
    public function toArray(): array
    {
        return [
            'subject_label' => $this->subjectLabel,
            'teacher_person_id' => $this->teacherPersonId,
            'structure_ids' => $this->structureIds,
            'weekday' => $this->weekday,
            'start_time' => $this->startTime,
            'end_time' => $this->endTime,
            'valid_from' => $this->validFrom,
            'valid_to' => $this->validTo,
            'room_id' => $this->roomId,
            'course_unit_id' => $this->courseUnitId,
            'session_kind' => $this->sessionKind,
        ];
    }
}
