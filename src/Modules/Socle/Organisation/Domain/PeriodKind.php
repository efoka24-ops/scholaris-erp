<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Domain;

/** Nature d'une période : trimestre, séquence ou semestre (le supérieur n'emploie que des semestres). */
enum PeriodKind: string
{
    case Trimester = 'trimester';
    case Sequence = 'sequence';
    case Semester = 'semester';

    public function allowedFor(EstablishmentType $type): bool
    {
        return $type === EstablishmentType::Higher ? $this === self::Semester : $this !== self::Semester;
    }
}
