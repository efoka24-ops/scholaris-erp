<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Teaching\Contracts;

/** Affectation d'un enseignant à un élément constitutif (EC) pour un groupe et une nature de séance (EX-ENS-02). */
final class TeachingAssignmentInfo
{
    public function __construct(
        public readonly string $id,
        public readonly string $personId,
        public readonly string $schoolId,
        public readonly string $departmentId,
        public readonly string $programId,
        public readonly string $levelId,
        public readonly string $groupId,
        public readonly string $curriculumId,
        public readonly string $elementId,
        public readonly string $elementCode,
        public readonly string $elementTitle,
        /** cm, td, tp ou other */
        public readonly string $sessionKind,
        public readonly int $plannedMinutes,
        public readonly string $academicYearId,
        /** active ou ended */
        public readonly string $status,
        /** affectation du socle (Staff, D17) */
        public readonly ?string $staffAssignmentId,
    ) {
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
