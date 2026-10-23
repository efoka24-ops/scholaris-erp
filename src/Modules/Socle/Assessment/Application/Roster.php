<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Application;

use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Scholaris\Modules\Socle\People\Contracts\StructureEnrollments;

/**
 * Élèves d'une structure (classe), vus par les contrats de People. Seules comptent les inscriptions actives de
 * l'établissement courant : un élève d'un autre établissement n'apparaît jamais, même si l'identifiant de classe est reconnu.
 */
final class Roster
{
    public function __construct(
        private readonly EnrollmentDirectory $enrollments,
        private readonly PersonDirectory $people,
    ) {
    }

    /** @return list<array{person_id: string, name: string, last_name: string, matricule: string|null}> triés par nom */
    public function students(string $tenantId, string $establishmentId, string $structureId): array
    {
        $ids = $this->enrollments->personIdsInStructure($tenantId, $structureId);
        $summaries = [];
        foreach ($this->people->findMany($tenantId, $ids) as $person) {
            $summaries[$person->id] = $person;
        }

        // Inscriptions de la classe lues en une requête quand People l'expose (P2/P3 : plus de lecture par élève).
        $byPerson = null;
        if ($this->enrollments instanceof StructureEnrollments) {
            $byPerson = [];
            foreach ($this->enrollments->inStructure($tenantId, $structureId) as $enrollment) {
                $byPerson[$enrollment->personId][] = $enrollment;
            }
        }

        $rows = [];
        foreach (array_unique($ids) as $personId) {
            $matricule = null;
            $inScope = false;
            foreach ($byPerson === null ? $this->enrollments->forPerson($tenantId, $personId) : ($byPerson[$personId] ?? []) as $enrollment) {
                if ($enrollment->structureId === $structureId && $enrollment->establishmentId === $establishmentId) {
                    $inScope = true;
                    $matricule = $enrollment->internalNumber;
                    break;
                }
            }
            if (!$inScope || !isset($summaries[$personId])) {
                continue;
            }
            $person = $summaries[$personId];
            $rows[] = ['person_id' => $personId, 'name' => $person->fullName(), 'last_name' => $person->lastName, 'matricule' => $matricule];
        }
        usort($rows, static fn (array $a, array $b): int => [mb_strtolower($a['last_name']), $a['name']] <=> [mb_strtolower($b['last_name']), $b['name']]);

        return $rows;
    }
}
