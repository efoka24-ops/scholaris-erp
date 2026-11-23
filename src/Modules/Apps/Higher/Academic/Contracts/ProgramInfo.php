<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Academic\Contracts;

/** Filière ou spécialité (lecture seule). L'identifiant est celui de la structure `track` du socle. */
final class ProgramInfo
{
    public function __construct(
        public readonly string $id,
        public readonly string $schoolId,
        public readonly string $schoolCode,
        public readonly string $departmentId,
        public readonly ?string $parentProgramId,
        public readonly string $code,
        public readonly string $name,
        /** lmd, bts, hnd ou certificate */
        public readonly string $regime,
        /** fr, en ou bilingual */
        public readonly string $teachingLanguage,
        /** day, evening ou work_study */
        public readonly string $studyMode,
        public readonly int $durationYears,
        /** active ou closed */
        public readonly string $status,
    ) {
    }
}
