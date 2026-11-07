<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Enrolment\Contracts;

/** Dossier secondaire d'un élève (lecture interne). Le matricule national est tenu par CarteScolaire (contrat NationalIds). */
final class StudentProfileInfo
{
    public function __construct(
        public readonly string $personId,
        public readonly bool $repeater,
        public readonly ?string $finalExclusionOn,
        /** année pour laquelle le chef d'établissement a décidé la réadmission d'un exclu définitif (RG-INS-3) */
        public readonly ?string $readmissionYearId,
    ) {
    }
}
