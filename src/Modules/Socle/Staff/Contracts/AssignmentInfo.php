<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Staff\Contracts;

/** Affectation d'enseignement vue par les autres modules. */
final class AssignmentInfo
{
    public function __construct(
        public readonly string $id,
        public readonly string $establishmentId,
        public readonly string $staffId,
        public readonly string $sessionKind,
        public readonly int $plannedMinutes,
        /** minutes d'heures validées rattachées à l'affectation */
        public readonly int $validatedMinutes,
        /** active ou ended */
        public readonly string $status,
        public readonly ?string $elementRef,
        public readonly ?string $groupRef,
        public readonly ?string $level,
        public readonly ?string $academicYearId,
        public readonly ?string $structureId,
        public readonly ?string $subjectLabel,
        public readonly ?string $startsOn,
        public readonly ?string $endsOn,
    ) {
    }
}
