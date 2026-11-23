<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Academic\Contracts;

/** Règlement des études d'une école, d'un régime et d'une année (EX-ACA-05). */
final class RegulationInfo
{
    /** @param array<string, bool|float|int|null> $parameters */
    public function __construct(
        public readonly string $id,
        public readonly string $schoolId,
        public readonly string $regime,
        public readonly string $academicYearId,
        public readonly int $version,
        /** draft ou frozen */
        public readonly string $status,
        public readonly array $parameters,
    ) {
    }

    public function isFrozen(): bool
    {
        return $this->status === 'frozen';
    }
}
