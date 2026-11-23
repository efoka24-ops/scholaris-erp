<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Admissions\Contracts;

/** Candidature telle qu'Enrolment la voit pour créer le dossier étudiant et l'inscription (EX-INS-01). */
final class AdmittedApplication
{
    /** @param list<string> $capitalizedUnitCodes UE accordées par équivalence (transfert, EX-ADM-07) */
    public function __construct(
        public readonly string $id,
        public readonly string $reference,
        public readonly string $personId,
        public readonly string $campaignId,
        public readonly string $schoolId,
        public readonly string $departmentId,
        public readonly string $programId,
        public readonly string $levelId,
        public readonly string $academicYearId,
        /** admitted, conditional, waitlisted, rejected, enrolled... */
        public readonly string $status,
        /** new ou transfer */
        public readonly string $kind,
        public readonly ?string $enrolmentId,
        public readonly array $capitalizedUnitCodes = [],
    ) {
    }

    public function isAdmitted(): bool
    {
        return $this->status === 'admitted';
    }
}
