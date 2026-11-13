<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Teaching\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\AcademicDirectory;
use Scholaris\Modules\Apps\Higher\Curriculum\Contracts\CurriculumDirectory;

/**
 * Situe un élément constitutif d'une maquette et un groupe dans l'organisation (école, département, filière, niveau) par les contrats
 * Academic et Curriculum. Un identifiant inconnu ou d'un autre client répond 404 ; un groupe d'un autre niveau 422.
 */
final class ElementResolver
{
    public function __construct(
        private readonly CurriculumDirectory $curricula,
        private readonly AcademicDirectory $academic,
    ) {
    }

    /**
     * @return array{school_id: string, department_id: string, program_id: string, level_id: string, group_id: string, academic_year_id: string,
     *     curriculum_id: string, element_id: string, element_code: string, element_title: string, level_code: string, program_name: string}
     */
    public function locate(string $tenantId, string $establishmentId, string $curriculumId, string $elementId, string $groupId): array
    {
        $curriculum = $this->curricula->find($tenantId, $establishmentId, $curriculumId) ?? throw HttpException::notFound('Maquette introuvable.');
        $element = null;
        foreach ($this->curricula->units($tenantId, $establishmentId, $curriculumId) as $unit) {
            foreach ($unit->elements as $candidate) {
                if ($candidate['id'] === $elementId) {
                    $element = $candidate;
                }
            }
        }
        if ($element === null) {
            throw HttpException::notFound('Élément constitutif introuvable dans cette maquette.');
        }
        $program = $this->academic->program($tenantId, $establishmentId, $curriculum->programId) ?? throw HttpException::notFound('Filière introuvable.');
        $level = $this->academic->level($tenantId, $establishmentId, $curriculum->levelId) ?? throw HttpException::notFound('Niveau introuvable.');
        $group = $this->academic->group($tenantId, $establishmentId, $groupId) ?? throw HttpException::notFound('Groupe introuvable.');
        if ($group->levelId !== $level->id) {
            throw HttpException::validation(['group_id' => ['Le groupe n\'appartient pas au niveau de la maquette.']]);
        }

        return [
            'school_id' => $program->schoolId, 'department_id' => $program->departmentId, 'program_id' => $program->id, 'level_id' => $level->id,
            'group_id' => $group->id, 'academic_year_id' => $curriculum->academicYearId, 'curriculum_id' => $curriculum->id, 'element_id' => $elementId,
            'element_code' => $element['code'], 'element_title' => $element['title'], 'level_code' => $level->code, 'program_name' => $program->name,
        ];
    }

    /**
     * Situe un groupe seul (cours mutualisé, délégués).
     *
     * @return array{school_id: string, department_id: string, program_id: string, level_id: string, group_id: string, capacity: ?int}
     */
    public function group(string $tenantId, string $establishmentId, string $groupId): array
    {
        $group = $this->academic->group($tenantId, $establishmentId, $groupId) ?? throw HttpException::notFound('Groupe introuvable.');
        $program = $this->academic->program($tenantId, $establishmentId, $group->programId) ?? throw HttpException::notFound('Filière introuvable.');

        return [
            'school_id' => $program->schoolId, 'department_id' => $program->departmentId, 'program_id' => $program->id, 'level_id' => $group->levelId,
            'group_id' => $group->id, 'capacity' => $group->capacity,
        ];
    }
}
