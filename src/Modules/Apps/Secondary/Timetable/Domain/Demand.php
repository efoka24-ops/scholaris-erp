<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Timetable\Domain;

/**
 * Besoin à placer par le générateur : une affectation (classe ou demi-groupe, matière, enseignant, salle) et le nombre de plages
 * hebdomadaires à trouver, tiré du volume horaire paramétré par l'établissement (une plage = une heure de cours).
 */
final class Demand
{
    public function __construct(
        public readonly string $assignmentId,
        public readonly string $classId,
        public readonly ?string $groupCode,
        public readonly string $teacherId,
        public readonly ?string $roomId,
        public readonly int $bandsNeeded,
        public readonly string $label,
    ) {
    }
}
