<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Enrolment\Contracts;

/** Dossier étudiant (EX-INS-01) : un seul par personne et établissement, un seul matricule définitif (RG-INS-1, RG-ORG-6). */
final class StudentInfo
{
    public function __construct(
        public readonly string $id,
        public readonly string $personId,
        /** null tant qu'aucune inscription n'a été validée */
        public readonly ?string $matricule,
        public readonly ?string $photoRef,
        public readonly ?string $guarantorPersonId,
        public readonly bool $sharesWithGuarantor,
    ) {
    }
}
