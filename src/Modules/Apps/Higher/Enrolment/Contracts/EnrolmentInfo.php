<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Enrolment\Contracts;

/** Inscription IPES d'une année dans une filière et un niveau (un « parcours » ; deux en double cursus). */
final class EnrolmentInfo
{
    public function __construct(
        public readonly string $id,
        public readonly string $personId,
        public readonly ?string $matricule,
        public readonly string $academicYearId,
        public readonly string $schoolId,
        public readonly string $programId,
        public readonly string $levelId,
        public readonly ?string $groupId,
        public readonly string $studyMode,
        /** pending, enrolled, financial_hold, suspended, withdrawn, excluded, transferred, graduated */
        public readonly string $status,
        /** version de maquette mémorisée (RG-MAQ-1) */
        public readonly ?string $curriculumId,
        /** rang de l'inscription dans ce niveau (RG-INS-5) */
        public readonly int $attemptNumber,
        /** inscription du socle liée (People, `enrollments`), null tant qu'elle n'est pas créée */
        public readonly ?string $enrollmentId,
    ) {
    }

    public function isValidated(): bool
    {
        return in_array($this->status, ['enrolled', 'financial_hold'], true);
    }
}
