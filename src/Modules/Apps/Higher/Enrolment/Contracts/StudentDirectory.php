<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Enrolment\Contracts;

/**
 * Dossiers étudiants et inscriptions IPES pour les autres sous-modules Higher (Grades, Attendance, Deliberation, Portals,
 * NationalExams…). Borné au client et à l'établissement ; aucun contrôle de périmètre (à faire par l'appelant).
 */
interface StudentDirectory
{
    public function student(string $tenantId, string $establishmentId, string $personId): ?StudentInfo;

    public function byMatricule(string $tenantId, string $establishmentId, string $matricule): ?StudentInfo;

    /** @return list<EnrolmentInfo> inscriptions de la personne (tous parcours : double cursus RG-ORG-6), la plus récente d'abord */
    public function enrolmentsOf(string $tenantId, string $establishmentId, string $personId): array;

    public function enrolment(string $tenantId, string $establishmentId, string $enrolmentId): ?EnrolmentInfo;

    /** @return list<EnrolmentInfo> inscriptions validées (inscrit ou sous réserve financière) d'un niveau ou d'un groupe pour l'année */
    public function enrolledIn(string $tenantId, string $establishmentId, string $structureId, string $academicYearId): array;

    /**
     * RG-INS-7 : le garant peut-il voir les notes et absences de l'étudiant ? (consentement enregistré et non retiré ; la situation
     * financière reste toujours visible du garant qui paie).
     */
    public function sharesWithGuarantor(string $tenantId, string $establishmentId, string $personId): bool;
}
