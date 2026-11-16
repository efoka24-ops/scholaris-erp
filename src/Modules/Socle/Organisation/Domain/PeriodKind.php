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

    /** Libellé lisible, ex. « Semestre 1 » (une seule période « année » : pas de numéro). */
    public function label(int $ordinal): string
    {
        return match ($this) {
            self::Year => 'Année',
            self::Trimester => 'Trimestre ' . $ordinal,
            self::Sequence => 'Séquence ' . $ordinal,
            self::Semester => 'Semestre ' . $ordinal,
        };
    }

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
