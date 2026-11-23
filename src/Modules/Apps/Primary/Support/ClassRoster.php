<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Support;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitTree;
use Scholaris\Modules\Socle\Organisation\Contracts\StructureInfo;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/**
 * Une classe de l'établissement vue par la saisie : sa structure, son niveau, les élèves inscrits (statut actif) et l'année de leurs
 * inscriptions. Une structure d'un autre établissement, d'un autre client ou qui n'est pas une classe répond 404.
 */
final class ClassRoster
{
    public function __construct(
        private readonly OrgUnitTree $tree,
        private readonly EnrollmentDirectory $enrollments,
        private readonly PersonDirectory $people,
    ) {
    }

    /** @return array{class: StructureInfo, level_id: ?string, learner_ids: list<string>, year_id: ?string} */
    public function resolve(string $tenantId, string $establishmentId, string $classId): array
    {
        $class = $this->tree->structure($tenantId, $establishmentId, $classId);
        if ($class === null || $class->kind !== 'class') {
            throw HttpException::notFound('Classe introuvable.');
        }
        $ids = $this->enrollments->personIdsInStructure($tenantId, $classId);
        $year = null;
        foreach ($ids as $personId) {
            foreach ($this->enrollments->forPerson($tenantId, $personId) as $enrollment) {
                if ($enrollment->structureId === $classId && $enrollment->establishmentId === $establishmentId) {
                    $year = $enrollment->academicYearId;
                    break 2;
                }
            }
        }

        return ['class' => $class, 'level_id' => $this->tree->levelOf($tenantId, $establishmentId, $classId), 'learner_ids' => $ids, 'year_id' => $year];
    }

    /**
     * @param list<string> $personIds
     * @return array<string, string> identifiant => « NOM Prénom »
     */
    public function names(string $tenantId, array $personIds): array
    {
        $names = [];
        foreach ($this->people->findMany($tenantId, $personIds) as $person) {
            $names[$person->id] = mb_strtoupper($person->lastName) . ' ' . $person->firstName;
        }

        return $names;
    }
}
