<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Pupils\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Apps\PublicSchools\Contracts\ClassRoster;
use Scholaris\Modules\Apps\PublicSchools\Pupils\Domain\DistributionPlanner;
use Scholaris\Modules\Socle\People\Contracts\PersonDemographics;

/**
 * Répartition proposée (EX-CLA-04, Could) des élèves de plusieurs classes d'un même niveau : lecture seule, rien n'est modifié ; le
 * directeur applique ensuite les déplacements qu'il retient.
 */
final class DistributionService
{
    public function __construct(
        private readonly ClassRoster $classes,
        private readonly PersonDemographics $demographics,
    ) {
    }

    /**
     * @param list<string> $classIds deux classes au moins, de l'école
     * @return array{classes: list<array{structure_id: string, label: string, pupil_ids: list<string>, headcount: int, female: int, male: int}>, moves: list<array{person_id: string, from_structure_id: string, to_structure_id: string}>}
     */
    public function propose(string $tenantId, string $establishmentId, array $classIds, string $date): array
    {
        $classIds = array_values(array_unique($classIds));
        if (count($classIds) < 2) {
            throw HttpException::validation(['class_ids' => ['Au moins deux classes sont nécessaires pour une répartition.']]);
        }
        $current = [];
        $labels = [];
        $pupilIds = [];
        foreach ($classIds as $classId) {
            $class = $this->classes->find($tenantId, $establishmentId, $classId) ?? throw HttpException::notFound('Classe introuvable : ' . $classId . '.');
            $labels[$classId] = $class->label;
            foreach ($this->classes->pupilIdsIn($tenantId, $establishmentId, $classId, $date) as $personId) {
                $current[$personId] = $classId;
                $pupilIds[] = $personId;
            }
        }
        $details = $this->demographics->ofPersons($tenantId, $pupilIds);
        $pupils = [];
        $sexes = [];
        foreach ($pupilIds as $personId) {
            $sex = $details[$personId]['gender'] ?? null;
            $sexes[$personId] = $sex;
            $pupils[] = ['id' => $personId, 'sex' => $sex, 'birth_date' => $details[$personId]['birth_date'] ?? null];
        }
        $plan = DistributionPlanner::plan($pupils, $classIds);
        $proposal = [];
        $moves = [];
        foreach ($plan as $classId => $members) {
            $proposal[] = [
                'structure_id' => $classId,
                'label' => $labels[$classId],
                'pupil_ids' => $members,
                'headcount' => count($members),
                'female' => count(array_filter($members, static fn (string $id): bool => $sexes[$id] === 'female')),
                'male' => count(array_filter($members, static fn (string $id): bool => $sexes[$id] === 'male')),
            ];
            foreach ($members as $personId) {
                if ($current[$personId] !== $classId) {
                    $moves[] = ['person_id' => $personId, 'from_structure_id' => $current[$personId], 'to_structure_id' => $classId];
                }
            }
        }

        return ['classes' => $proposal, 'moves' => $moves];
    }
}
