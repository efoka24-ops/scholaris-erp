<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Setup\Contracts;

/**
 * Profil d'une classe du secondaire pour une année (RG-ORG-2) : section, enseignement, cycle, niveau et, au 2nd cycle ou en
 * technique, série ou spécialité. La classe elle-même est une structure du socle (`structureId`).
 */
final class ClassProfileInfo
{
    public function __construct(
        public readonly string $id,
        public readonly string $structureId,
        public readonly string $academicYearId,
        public readonly string $label,
        public readonly string $sectionCode,
        public readonly string $teachingKind,
        public readonly string $cycleCode,
        public readonly string $levelId,
        public readonly string $levelCode,
        public readonly ?string $streamId,
        public readonly ?string $examCode,
        public readonly ?int $capacity,
    ) {
    }
}
