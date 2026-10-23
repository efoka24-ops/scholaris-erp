<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Scheduling\Contracts;

/** Occurrence datée d'un créneau, modifications ponctuelles, jours fériés et fermetures appliqués (D13). */
final class OccurrenceInfo
{
    public const PLANNED = 'planned';
    public const REPLACED = 'replaced';
    public const MOVED_IN = 'moved_in';
    public const MOVED_OUT = 'moved_out';
    public const CANCELLED = 'cancelled';

    /** @param list<string> $structureIds */
    public function __construct(
        public readonly string $slotId,
        public readonly string $date,
        public readonly string $startTime,
        public readonly string $endTime,
        public readonly int $minutes,
        public readonly string $teacherPersonId,
        public readonly array $structureIds,
        public readonly ?string $courseUnitId,
        public readonly ?string $sessionKind,
        /** une des constantes ci-dessus */
        public readonly string $status,
    ) {
    }

    /** Le cours a lieu ce jour-là (prévu, remplacé ou déplacé vers ce jour). */
    public function isHeld(): bool
    {
        return in_array($this->status, [self::PLANNED, self::REPLACED, self::MOVED_IN], true);
    }

    /** Occurrence prévue par l'emploi du temps à cette date (tenue, remplacée, annulée ou déplacée ailleurs). */
    public function isScheduled(): bool
    {
        return $this->status !== self::MOVED_IN;
    }
}
