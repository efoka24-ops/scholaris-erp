<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Academic\Contracts;

/** Groupe d'un niveau, identifiant = structure `group` du socle. */
final class GroupInfo
{
    public function __construct(
        public readonly string $id,
        public readonly string $levelId,
        public readonly string $programId,
        public readonly string $code,
        public readonly string $name,
        public readonly ?int $capacity,
    ) {
    }
}
