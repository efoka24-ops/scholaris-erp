<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Curriculum\Contracts;

/**
 * Point d'extension (RG-MAQ-1) : un sous-module qui saisit des notes (Grades, vague 2) dit si une version de maquette en porte.
 * Enregistrement : entrée de liste du conteneur `higher.curriculum.grade_probes.<module>` => [classe ou instance, …].
 * Complète CurriculumDirectory::markGradesRecorded.
 */
interface GradeProbe
{
    public const PREFIX = 'higher.curriculum.grade_probes.';

    public function hasGrades(string $tenantId, string $establishmentId, string $curriculumId): bool;
}
