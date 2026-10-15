<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Contracts;

/** Matière ou élément du référentiel tel qu'il est enregistré (lecture du catalogue de programme, D9). */
final class CurriculumEntry
{
    public function __construct(
        public readonly string $id,
        public readonly string $externalKey,
        public readonly ?string $subjectId,
        public readonly string $code,
        public readonly string $name,
        public readonly string $kind,
        public readonly float $coefficient,
        public readonly int $credits,
        public readonly bool $active,
    ) {
    }

    public function isElement(): bool
    {
        return $this->subjectId !== null;
    }
}
