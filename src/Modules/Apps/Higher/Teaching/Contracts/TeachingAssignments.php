<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Teaching\Contracts;

/**
 * Affectations d'enseignement de l'IPES pour les autres sous-modules Higher (Grades : un enseignant ne saisit que ses EC et groupes,
 * RG-NOT-3, TST-03 ; Vacations ; portails). Borné au client et à l'établissement ; aucun contrôle de périmètre (à faire par l'appelant).
 */
interface TeachingAssignments
{
    public function find(string $tenantId, string $establishmentId, string $assignmentId): ?TeachingAssignmentInfo;

    /** @return list<TeachingAssignmentInfo> affectations actives de l'enseignant (toutes années si $academicYearId est nul) */
    public function forTeacher(string $tenantId, string $establishmentId, string $personId, ?string $academicYearId = null): array;

    /** @return list<TeachingAssignmentInfo> affectations actives d'un EC, éventuellement limitées à un groupe */
    public function forElement(string $tenantId, string $establishmentId, string $elementId, ?string $groupId = null): array;

    /** L'enseignant a-t-il une affectation active sur cet EC (et ce groupe s'il est donné), toutes natures confondues ? */
    public function isAssigned(string $tenantId, string $establishmentId, string $personId, string $elementId, ?string $groupId = null): bool;
}
