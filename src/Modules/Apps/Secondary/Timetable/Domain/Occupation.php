<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Timetable\Domain;

/**
 * Occupation d'un créneau hebdomadaire : enseignant, classe (ou demi-groupe de la classe), salle ou atelier, jour, heures HH:MM et
 * période de validité AAAA-MM-JJ. Les heures sont comparées comme chaînes (HH:MM sur deux chiffres) : aucune durée n'est codée.
 */
final class Occupation
{
    public function __construct(
        public readonly string $ref,
        public readonly int $weekday,
        public readonly string $startTime,
        public readonly string $endTime,
        public readonly ?string $teacherId,
        public readonly ?string $classId,
        /** null = toute la classe ; G1, G2 = demi-groupe */
        public readonly ?string $groupCode,
        public readonly ?string $roomId,
        public readonly string $validFrom = '',
        public readonly ?string $validTo = null,
        public readonly string $label = '',
    ) {
    }

    public function overlaps(self $other): bool
    {
        return $this->weekday === $other->weekday
            && $this->startTime < $other->endTime && $other->startTime < $this->endTime
            && ($this->validTo === null || $this->validTo >= $other->validFrom)
            && ($other->validTo === null || $other->validTo >= $this->validFrom);
    }

    /** Même classe au même moment : toute la classe heurte tout ; deux demi-groupes différents peuvent avoir cours ensemble. */
    public function sharesClass(self $other): bool
    {
        if ($this->classId === null || $this->classId !== $other->classId) {
            return false;
        }

        return $this->groupCode === null || $other->groupCode === null || $this->groupCode === $other->groupCode;
    }
}
