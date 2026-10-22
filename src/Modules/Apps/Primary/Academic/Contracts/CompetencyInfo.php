<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Academic\Contracts;

/** Compétence du référentiel d'une année et d'un niveau. */
final class CompetencyInfo
{
    public function __construct(
        public readonly string $id,
        public readonly string $academicYearId,
        public readonly string $levelStructureId,
        public readonly string $domainId,
        public readonly ?string $subjectId,
        public readonly string $code,
        public readonly string $labelFr,
        public readonly ?string $labelEn,
        public readonly int $sortOrder,
        public readonly bool $active,
    ) {
    }

    public function label(string $locale): string
    {
        return $locale === 'en' && $this->labelEn !== null && $this->labelEn !== '' ? $this->labelEn : $this->labelFr;
    }
}
