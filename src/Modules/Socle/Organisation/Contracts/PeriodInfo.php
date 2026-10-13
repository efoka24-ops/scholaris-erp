<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Contracts;

/** Période pédagogique telle que les autres modules la voient (lecture seule). */
final class PeriodInfo
{
    public function __construct(
        public readonly string $id,
        public readonly string $academicYearId,
        public readonly ?string $academicYearLabel,
        /** trimester, sequence ou semester */
        public readonly string $kind,
        public readonly int $ordinal,
        public readonly string $startsOn,
        public readonly string $endsOn,
    ) {
    }
}
