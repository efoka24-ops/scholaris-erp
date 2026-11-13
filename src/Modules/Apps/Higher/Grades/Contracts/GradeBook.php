<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Grades\Contracts;

/**
 * Lecture des notes IPES pour les autres sous-modules Higher (Deliberation : RG-DEL-1 et calcul ; Teaching : RG-ENS-5 saisie
 * complète ; Portals). Borné au client et à l'établissement ; aucun contrôle de périmètre (à faire par l'appelant).
 */
interface GradeBook
{
    /**
     * Évaluations d'une maquette (et d'un semestre), avec leur état.
     *
     * @return list<array{id: string, element_id: string, unit_id: string, group_id: ?string, kind: string, exam_session: string,
     *     max_score: float, weight_percent: int, status: string, semester_ordinal: int}>
     */
    public function assessmentsOf(string $tenantId, string $establishmentId, string $curriculumId, ?int $semesterOrdinal = null): array;

    /** RG-DEL-1 : toutes les évaluations de la maquette (et du semestre) sont-elles verrouillées (ou publiées) ? */
    public function allLocked(string $tenantId, string $establishmentId, string $curriculumId, ?int $semesterOrdinal = null): bool;

    /**
     * Notes d'une évaluation (cellules saisies), anonymat levé ou non : la personne est toujours connue en interne.
     *
     * @return list<array{person_id: string, score: ?float, absence: ?string, version: int}>
     */
    public function marksOf(string $tenantId, string $establishmentId, string $assessmentId): array;

    /** Saisie complète d'un EC (toutes les évaluations soumises au moins, aucune cellule vide) : RG-ENS-5. */
    public function elementComplete(string $tenantId, string $establishmentId, string $elementId, ?string $groupId = null): bool;
}
