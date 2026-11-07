<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Setup\Contracts;

/**
 * Coefficient et volume horaire en vigueur d'une matière pour un niveau (et une série ou spécialité) d'une année. Valeurs saisies
 * par l'établissement (ou fixées par la plateforme : `locked`), jamais codées. Une matière de projet (`project`) n'entre pas dans
 * la moyenne officielle (RG-ORG-4).
 */
final class CoefficientLine
{
    public function __construct(
        public readonly string $id,
        public readonly string $subjectId,
        public readonly string $localSubjectId,
        public readonly string $code,
        public readonly string $label,
        public readonly string $levelCode,
        public readonly ?string $streamId,
        public readonly float $coefficient,
        public readonly float $weeklyHours,
        /** official, project, practical, internship */
        public readonly string $role,
        public readonly bool $countsInAverage,
        public readonly bool $locked,
        /** example, official, custom */
        public readonly string $origin,
        public readonly int $versionNo,
        public readonly string $validFrom,
    ) {
    }
}
