<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Curriculum\Contracts;

/**
 * Lecture des maquettes pour les autres sous-modules Higher (Enrolment : version de l'année, UE à choix ; Grades, Teaching…).
 * Borné au client et à l'établissement ; aucun contrôle de périmètre (à faire par l'appelant).
 */
interface CurriculumDirectory
{
    /** Version publiée en vigueur pour la filière, le niveau et l'année (celle à mémoriser à l'inscription, RG-MAQ-1). */
    public function publishedFor(string $tenantId, string $establishmentId, string $programId, string $levelId, string $academicYearId): ?CurriculumSummary;

    /** Une version précise (y compris remplacée : les étudiants inscrits sur elle y restent). */
    public function find(string $tenantId, string $establishmentId, string $curriculumId): ?CurriculumSummary;

    /** @return list<CurriculumUnitInfo> UE de la version, par semestre puis ordre */
    public function units(string $tenantId, string $establishmentId, string $curriculumId): array;

    /**
     * Signale que des notes ont été saisies sur cette version (appelé par la saisie des notes) : elle ne pourra plus être
     * dépubliée ni modifiée, seule une nouvelle version est possible (RG-MAQ-1). Idempotent.
     */
    public function markGradesRecorded(string $tenantId, string $establishmentId, string $curriculumId): void;
}
