<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Scheduling\Contracts;

/** Créneau d'emploi du temps vu par les autres modules (lecture seule). */
final class SlotInfo
{
    public function __construct(
        public readonly string $id,
        public readonly string $tenantId,
        public readonly string $establishmentId,
        /** classe, groupe ou filière concernée (identifiant de structure pédagogique) */
        public readonly string $structureId,
        public readonly string $teacherPersonId,
        public readonly string $subjectLabel,
        /** 1 = lundi … 7 = dimanche */
        public readonly int $weekday,
        /** HH:MM, fuseau Africa/Douala */
        public readonly string $startTime,
        public readonly string $endTime,
        public readonly string $validFrom,
        public readonly ?string $validTo = null,
        public readonly ?string $roomId = null,
        /** élément constitutif (EC) ou unité d'enseignement rattachée, si connue */
        public readonly ?string $courseUnitId = null,
        /** nature de la séance (SessionKinds), null si non précisée */
        public readonly ?string $sessionKind = null,
    ) {
    }
}
