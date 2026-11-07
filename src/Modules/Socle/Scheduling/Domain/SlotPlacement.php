<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Scheduling\Domain;

/** Emplacement d'un créneau dans la semaine, pour la détection de conflits. */
final class SlotPlacement
{
    private const FOREVER = '9999-12-31';

    /** @param list<string> $structureIds */
    public function __construct(
        public readonly ?string $id,
        public readonly string $teacherPersonId,
        public readonly ?string $roomId,
        public readonly array $structureIds,
        public readonly int $weekday,
        public readonly string $startTime,
        public readonly string $endTime,
        public readonly string $validFrom,
        public readonly ?string $validTo = null,
    ) {
    }

    /** Même jour de la semaine, plages horaires qui se chevauchent (bornes jointives permises) et périodes de validité qui se recouvrent. */
    public function overlaps(self $other): bool
    {
        return $this->weekday === $other->weekday
            && $this->startTime < $other->endTime && $other->startTime < $this->endTime
            && $this->validFrom <= ($other->validTo ?? self::FOREVER) && $other->validFrom <= ($this->validTo ?? self::FOREVER);
    }
}
