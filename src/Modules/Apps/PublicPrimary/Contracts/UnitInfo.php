<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicPrimary\Contracts;

/** Unité d'apprentissage (EX-EVA-02) : rattachée à un trimestre (période du socle), avec sa semaine d'intégration et d'évaluation. */
final class UnitInfo
{
    public function __construct(
        public readonly string $id,
        public readonly string $yearLabel,
        public readonly string $levelCode,
        public readonly int $unitNo,
        public readonly string $periodId,
        public readonly string $startsOn,
        public readonly string $endsOn,
        public readonly string $evaluationWeekFrom,
        public readonly bool $isFinalIntegration,
    ) {
    }
}
