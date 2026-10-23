<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Scheduling\Application;

use Scholaris\Modules\Socle\Scheduling\Contracts\OccurrenceInfo;
use Scholaris\Modules\Socle\Scheduling\Contracts\SlotInfo;
use Scholaris\Modules\Socle\Scheduling\Domain\Hhmm;

/** Une occurrence datée d'un créneau, modifications ponctuelles appliquées. */
final class Occurrence
{
    public const PLANNED = 'planned';
    public const REPLACED = 'replaced';
    public const MOVED_IN = 'moved_in';
    public const MOVED_OUT = 'moved_out';
    public const CANCELLED = 'cancelled';

    /** @param list<string> $structureIds */
    public function __construct(
        public readonly string $slotId,
        public readonly string $tenantId,
        public readonly string $establishmentId,
        public readonly array $structureIds,
        public readonly string $teacherPersonId,
        public readonly string $subjectLabel,
        public readonly ?string $roomId,
        public readonly ?string $courseUnitId,
        public readonly string $date,
        public readonly string $startTime,
        public readonly string $endTime,
        public readonly string $validFrom,
        public readonly ?string $validTo,
        public readonly string $status,
        public readonly ?string $changeId = null,
        public readonly ?string $originalTeacherPersonId = null,
        public readonly ?string $sessionKind = null,
    ) {
    }

    /** Le cours a effectivement lieu ce jour-là. */
    public function isHeld(): bool
    {
        return $this->status === self::PLANNED || $this->status === self::REPLACED || $this->status === self::MOVED_IN;
    }

    public function toInfo(): OccurrenceInfo
    {
        return new OccurrenceInfo(
            $this->slotId,
            $this->date,
            $this->startTime,
            $this->endTime,
            $this->minutes(),
            $this->teacherPersonId,
            $this->structureIds,
            $this->courseUnitId,
            $this->sessionKind,
            $this->status,
        );
    }

    public function minutes(): int
    {
        return Hhmm::duration($this->startTime, $this->endTime);
    }

    /**
     * Une vue par structure : un cours mutualisé donne plusieurs SlotInfo de même identifiant.
     *
     * @return list<SlotInfo>
     */
    public function toSlotInfos(): array
    {
        $weekday = (int) (new \DateTimeImmutable($this->date))->format('N');

        return array_map(fn (string $structureId): SlotInfo => new SlotInfo(
            $this->slotId,
            $this->tenantId,
            $this->establishmentId,
            $structureId,
            $this->teacherPersonId,
            $this->subjectLabel,
            $weekday,
            $this->startTime,
            $this->endTime,
            $this->validFrom,
            $this->validTo,
            $this->roomId,
            $this->courseUnitId,
            $this->sessionKind,
        ), $this->structureIds);
    }
}
