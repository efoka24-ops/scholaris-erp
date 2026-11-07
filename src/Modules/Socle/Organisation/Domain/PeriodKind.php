<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Domain;

/**
 * Nature d'une période : année, trimestre, séquence ou semestre (le supérieur n'emploie que des semestres).
 * Hiérarchie hors supérieur : année > trimestre > séquence (une seule période `year` par année scolaire).
 */
enum PeriodKind: string
{
    case Year = 'year';
    case Trimester = 'trimester';
    case Sequence = 'sequence';
    case Semester = 'semester';

    public function allowedFor(EstablishmentType $type): bool
    {
        return $type === EstablishmentType::Higher ? $this === self::Semester : $this !== self::Semester;
    }

    /** Nature de la période parente admise : null quand la période ne se rattache à aucune autre. */
    public function parentKind(): ?self
    {
        return match ($this) {
            self::Sequence => self::Trimester,
            self::Trimester => self::Year,
            self::Year, self::Semester => null,
        };
    }

    /** Le parent est-il obligatoire ? Seule la séquence l'exige (le trimestre peut rester sans année parente). */
    public function requiresParent(): bool
    {
        return $this === self::Sequence;
    }
}
