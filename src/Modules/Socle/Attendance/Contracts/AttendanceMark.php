<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Attendance\Contracts;

/** Statut d’appel d’une personne pour une demi-journée. */
final class AttendanceMark
{
    public const PRESENT = 'P';
    public const ABSENT = 'A';
    public const LATE = 'R';

    public function __construct(
        public readonly string $personId,
        public readonly string $status = self::PRESENT,
        public readonly ?string $arrivedAt = null,
        public readonly ?string $clientUuid = null,
        public readonly ?string $note = null,
    ) {
        if (!in_array($status, [self::PRESENT, self::ABSENT, self::LATE], true)) {
            throw new \InvalidArgumentException('Statut d’appel invalide.');
        }
    }
}
