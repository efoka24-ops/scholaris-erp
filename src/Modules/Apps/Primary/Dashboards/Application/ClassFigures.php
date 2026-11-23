<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Dashboards\Application;

use Scholaris\Modules\Apps\Primary\Academic\Contracts\LevelDirectory;
use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitTree;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;

/**
 * Les classes primaires d'un établissement : celles des niveaux qui ont un profil primaire, avec leurs élèves inscrits. Un établissement
 * sans niveau primaire n'a aucune classe (le tableau de bord et les contrôles de clôture sont alors « sans objet »).
 */
final class ClassFigures
{
    public function __construct(
        private readonly LevelDirectory $levels,
        private readonly OrgUnitTree $tree,
        private readonly EnrollmentDirectory $enrollments,
    ) {
    }

    /** @return list<ClassFigure> */
    public function all(string $tenantId, string $establishmentId): array
    {
        $classes = [];
        foreach ($this->levels->all($tenantId, $establishmentId) as $profile) {
            foreach ($this->tree->structureDescendantsOf($tenantId, $establishmentId, $profile->levelStructureId) as $structureId) {
                if ($structureId === $profile->levelStructureId) {
                    continue;
                }
                $info = $this->tree->structure($tenantId, $establishmentId, $structureId);
                if ($info === null || $info->kind !== 'class') {
                    continue;
                }
                $classes[] = new ClassFigure(
                    $structureId,
                    $info->name,
                    $profile->levelStructureId,
                    $info->capacity,
                    $this->enrollments->personIdsInStructure($tenantId, $structureId),
                );
            }
        }

        return $classes;
    }
}
