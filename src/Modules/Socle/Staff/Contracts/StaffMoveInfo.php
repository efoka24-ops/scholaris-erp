<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Staff\Contracts;

/** Une mutation de personnel, telle que l'historique la rend (lecture seule). */
final class StaffMoveInfo
{
    public function __construct(
        public readonly string $id,
        public readonly string $staffId,
        public readonly string $fromEstablishmentId,
        public readonly string $toEstablishmentId,
        public readonly string $effectiveOn,
        public readonly ?string $reason,
        public readonly int $endedAssignments,
    ) {
    }
}
