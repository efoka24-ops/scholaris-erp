<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Staff\Contracts;

/** Heure réalisée soumise aux contrôles de payabilité (VacationPayabilityCheck). */
final class PayableHour
{
    public function __construct(
        public readonly string $id,
        public readonly string $tenantId,
        public readonly string $establishmentId,
        public readonly string $staffId,
        public readonly string $personId,
        /** permanent, vacataire ou invite */
        public readonly string $staffKind,
        public readonly string $heldOn,
        public readonly int $minutes,
        public readonly string $sessionKind,
        public readonly ?string $assignmentId,
        public readonly ?string $elementRef,
        public readonly ?string $groupRef,
        public readonly ?string $level,
        public readonly ?string $slotId,
        public readonly ?string $content,
        public readonly ?string $teacherSignedAt,
        public readonly ?string $declaredBy,
        /** déclarée, validée ou rejetée */
        public readonly string $status,
    ) {
    }
}
