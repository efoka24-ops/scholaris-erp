<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Timetable\Contracts;

/**
 * Affectations d'enseignement et créneaux du secondaire (contrat interne, fourni par Timetable) : cahier de textes (Syllabus),
 * appel par heure de cours (Discipline), grille de saisie de l'enseignant (Grades), heures du personnel (Staff). Lecture seule,
 * client et établissement imposés.
 */
interface TeachingAssignments
{
    public function find(string $tenantId, string $establishmentId, string $assignmentId): ?AssignmentInfo;

    /** @return list<AssignmentInfo> affectations actives d'un enseignant, de toutes ses classes */
    public function forTeacher(string $tenantId, string $establishmentId, string $personId, ?string $academicYearId = null): array;

    /** @return list<AssignmentInfo> affectations actives d'une classe (demi-groupes compris) */
    public function forClass(string $tenantId, string $establishmentId, string $classId, ?string $academicYearId = null): array;

    /** @return list<AssignmentInfo> toutes les affectations actives de l'établissement pour l'année */
    public function forYear(string $tenantId, string $establishmentId, string $academicYearId): array;

    public function entry(string $tenantId, string $establishmentId, string $entryId): ?EntryInfo;

    /** @return list<EntryInfo> créneaux actifs d'une affectation (toutes périodes de validité) */
    public function entriesOf(string $tenantId, string $establishmentId, string $assignmentId): array;
}
