<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Contracts;

/**
 * Matière (unité d'enseignement) ou élément (EC) projeté par une application dans le référentiel d'évaluation (D9).
 * La clé externe (identifiant de l'UE ou de l'EC dans la maquette de l'application) rend l'enregistrement idempotent.
 * Pour un élément, $kind est ignoré.
 */
final class CurriculumItem
{
    public function __construct(
        public readonly string $externalKey,
        public readonly string $code,
        public readonly string $name,
        public readonly string $kind = 'teaching_unit',
        public readonly float $coefficient = 1.0,
        public readonly int $credits = 0,
    ) {
    }
}
