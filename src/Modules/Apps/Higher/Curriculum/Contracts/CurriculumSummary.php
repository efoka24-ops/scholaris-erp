<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Curriculum\Contracts;

/** Maquette (une version) telle que les autres sous-modules la voient : l'inscription mémorise son identifiant (RG-MAQ-1). */
final class CurriculumSummary
{
    public function __construct(
        public readonly string $id,
        public readonly string $programId,
        public readonly string $levelId,
        public readonly string $academicYearId,
        public readonly string $regime,
        public readonly int $version,
        /** draft, published ou superseded */
        public readonly string $status,
        public readonly string $title,
        public readonly ?string $approvedProgrammeRef,
        public readonly bool $gradesRecorded,
    ) {
    }
}
