<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Attendance\Contracts;

/** Représentation minimale d’une séance d’appel accessible aux applications. */
final class AttendanceSession
{
    /** @param list<AttendanceMark> $marks */
    public function __construct(
        public readonly string $id,
        public readonly string $establishmentId,
        public readonly string $structureId,
        public readonly string $date,
        public readonly string $dayPart,
        public readonly string $status,
        public readonly array $marks,
    ) {
    }
}
