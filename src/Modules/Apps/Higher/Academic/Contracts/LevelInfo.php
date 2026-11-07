<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Academic\Contracts;

/** Niveau d'une filière (L1, M2, BTS1…), identifiant = structure `level` du socle. */
final class LevelInfo
{
    public function __construct(
        public readonly string $id,
        public readonly string $programId,
        public readonly int $ordinal,
        public readonly string $code,
        public readonly string $name,
        /** dernier niveau de la filière (année de sortie, EXN) */
        public readonly bool $isFinal,
    ) {
    }
}
