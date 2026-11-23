<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Staff\Contracts;

/** Affectation à créer par une application (AssignmentWriter, D17) : enseignant, élément constitutif, groupe, nature, volume. */
final class AssignmentDraft
{
    public function __construct(
        /** fiche du personnel (staff_members.id, voir StaffInfo::$staffId) */
        public readonly string $staffId,
        /** cm, td, tp ou other */
        public readonly string $sessionKind,
        public readonly int $plannedMinutes,
        /** élément constitutif de l'application (référence externe) */
        public readonly ?string $elementRef = null,
        /** groupe de l'application (référence externe) */
        public readonly ?string $groupRef = null,
        /** niveau d'enseignement (L1, M1, BTS…) servant au taux de vacation */
        public readonly ?string $level = null,
        public readonly ?string $academicYearId = null,
        public readonly ?string $structureId = null,
        public readonly ?string $subjectLabel = null,
        public readonly ?string $startsOn = null,
        public readonly ?string $endsOn = null,
    ) {
    }
}
