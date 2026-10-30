<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Domain;

/** Structure pédagogique : niveau, classe, groupe ou filière (la filière n'existe que dans le supérieur). */
enum StructureKind: string
{
    case Level = 'level';
    case SchoolClass = 'class';
    case Group = 'group';
    case Track = 'track';

    public function allowedFor(EstablishmentType $type): bool
    {
        return $this !== self::Track || $type === EstablishmentType::Higher;
    }
}
