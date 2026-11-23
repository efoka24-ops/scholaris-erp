<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Contracts;

/** Structure pédagogique à créer par une application (D1). */
final class StructureDraft
{
    public const TRACK = 'track';
    public const LEVEL = 'level';
    public const GROUP = 'group';
    public const SCHOOL_CLASS = 'class';

    public function __construct(
        /** track, level, group ou class */
        public readonly string $kind,
        public readonly string $name,
        public readonly ?string $orgUnitId = null,
        /** structure parente : niveau sous une filière, groupe sous un niveau */
        public readonly ?string $parentId = null,
        public readonly ?int $capacity = null,
    ) {
    }
}
