<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Teaching\Contracts;

/** Séance consignée au cahier de textes (EX-ENS-04), vue par les autres sous-modules Higher (Vacations : état mensuel). */
final class TaughtSessionInfo
{
    public function __construct(
        public readonly string $id,
        public readonly string $assignmentId,
        public readonly string $personId,
        public readonly string $schoolId,
        public readonly string $departmentId,
        public readonly string $programId,
        public readonly string $levelId,
        public readonly string $groupId,
        public readonly string $elementId,
        public readonly string $elementCode,
        public readonly string $elementTitle,
        /** cm, td, tp ou other */
        public readonly string $sessionKind,
        public readonly string $heldOn,
        public readonly int $minutes,
        /** draft, signed, countersigned, validated ou rejected */
        public readonly string $status,
        public readonly ?string $teacherSignedAt,
        public readonly ?string $content,
        public readonly ?string $slotId,
        /** heure du socle (TaughtHoursRecorder) liée à la séance */
        public readonly ?string $vacationHourId,
        public readonly ?string $staffId,
        public readonly ?string $staffAssignmentId,
    ) {
    }

    /** Heures validées par le chef de département (EX-ENS-06). */
    public function isValidated(): bool
    {
        return $this->status === 'validated';
    }
}
