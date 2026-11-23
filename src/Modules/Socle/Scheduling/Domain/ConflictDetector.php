<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Scheduling\Domain;

/**
 * Détection de conflits d'emploi du temps : même enseignant, même salle ou même structure (classe, groupe) sur des
 * plages qui se chevauchent, plus capacité de la salle si elle est connue. Un cours mutualisé s'exprime par un seul
 * créneau portant plusieurs structures : il ne se heurte pas à lui-même.
 */
final class ConflictDetector
{
    /**
     * @param list<SlotPlacement> $others créneaux déjà en place (le candidat lui-même est ignoré par son id)
     * @return list<Conflict>
     */
    public function detect(SlotPlacement $candidate, array $others, ?int $roomCapacity = null, ?int $headcount = null): array
    {
        $conflicts = [];
        foreach ($others as $other) {
            if ($candidate->id !== null && $other->id === $candidate->id) {
                continue;
            }
            if (!$candidate->overlaps($other)) {
                continue;
            }
            $when = sprintf('%s-%s', $other->startTime, $other->endTime);
            if ($candidate->teacherPersonId === $other->teacherPersonId) {
                $conflicts[] = new Conflict(Conflict::TEACHER, 'L\'enseignant a déjà un cours de ' . $when . ' sur cette plage.', $other->id);
            }
            if ($candidate->roomId !== null && $candidate->roomId === $other->roomId) {
                $conflicts[] = new Conflict(Conflict::ROOM, 'La salle est déjà occupée de ' . $when . '.', $other->id);
            }
            $shared = array_values(array_intersect($candidate->structureIds, $other->structureIds));
            if ($shared !== []) {
                $conflicts[] = new Conflict(Conflict::STRUCTURE, 'Une classe ou un groupe concerné a déjà cours de ' . $when . '.', $other->id, $shared);
            }
        }
        if ($roomCapacity !== null && $headcount !== null && $headcount > $roomCapacity) {
            $conflicts[] = new Conflict(Conflict::CAPACITY, sprintf('La salle (%d places) ne peut pas accueillir %d apprenants.', $roomCapacity, $headcount));
        }

        return $conflicts;
    }
}
