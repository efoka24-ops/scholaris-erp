<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Academic\Contracts;

/** Rattachement d'une filière LMD à son université de tutelle pour une année académique (RG-ORG-8). */
final class TutelageInfo
{
    public function __construct(
        public readonly string $id,
        public readonly string $programId,
        public readonly string $academicYearId,
        public readonly string $universityName,
        public readonly ?string $conventionReference,
        public readonly ?string $conventionEndsOn,
        /** référence du programme approuvé par la tutelle (RG-MAQ-3) */
        public readonly ?string $approvedProgrammeRef,
    ) {
    }
}
