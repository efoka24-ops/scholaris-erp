<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Contracts;

/** Structure pédagogique telle que les autres modules la voient (lecture seule). */
final class StructureInfo
{
    public function __construct(
        public readonly string $id,
        public readonly string $establishmentId,
        public readonly string $kind,
        public readonly string $name,
        public readonly ?string $orgUnitId,
        public readonly ?string $parentId,
        public readonly ?int $capacity,
    ) {
    }
}
