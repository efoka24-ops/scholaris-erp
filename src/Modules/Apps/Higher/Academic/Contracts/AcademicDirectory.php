<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Academic\Contracts;

/**
 * Lecture du paramétrage académique pour les autres sous-modules Higher (Curriculum, Admissions, Enrolment…). Toujours borné
 * au client et à l'établissement : un identifiant d'ailleurs donne null. Aucun contrôle de périmètre : à faire par l'appelant.
 */
interface AcademicDirectory
{
    public function school(string $tenantId, string $establishmentId, string $schoolId): ?SchoolInfo;

    public function program(string $tenantId, string $establishmentId, string $programId): ?ProgramInfo;

    public function level(string $tenantId, string $establishmentId, string $levelId): ?LevelInfo;

    /** @return list<LevelInfo> niveaux de la filière par ordre */
    public function levelsOf(string $tenantId, string $establishmentId, string $programId): array;

    public function group(string $tenantId, string $establishmentId, string $groupId): ?GroupInfo;

    /** @return list<GroupInfo> groupes du niveau par code */
    public function groupsOf(string $tenantId, string $establishmentId, string $levelId): array;

    /** Tutelle de la filière pour l'année (RG-ORG-8), null s'il n'y en a pas. */
    public function tutelageFor(string $tenantId, string $establishmentId, string $programId, string $academicYearId): ?TutelageInfo;
}
