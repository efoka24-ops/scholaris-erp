<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Academic\Contracts;

/** Profil d'un niveau de l'école primaire (lecture seule pour les autres sous-modules Primary). */
final class LevelProfile
{
    public function __construct(
        public readonly string $id,
        public readonly string $levelStructureId,
        public readonly ?string $sectionOrgUnitId,
        public readonly ?string $cycleCode,
        public readonly int $sortOrder,
        public readonly bool $isFinalLevel,
        public readonly bool $isExamLevel,
        public readonly string $language,
    ) {
    }
}
