<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Setup\Contracts;

/** Classes du secondaire (contrat interne de Secondary, fourni par Setup) : Enrolment, Timetable, Councils, OfficialExams. */
interface ClassProfiles
{
    /** Profil le plus récent de la structure de classe dans l'établissement, sinon null. */
    public function find(string $tenantId, string $establishmentId, string $structureId): ?ClassProfileInfo;

    /** @return list<ClassProfileInfo> classes de l'année, triées par niveau puis libellé */
    public function forYear(string $tenantId, string $establishmentId, string $academicYearId): array;

    /**
     * Niveau de l'établissement par identifiant (se_levels), sinon null : `cycle_code`, `code`, `label`, `structure_id`.
     *
     * @return array<string, mixed>|null
     */
    public function level(string $tenantId, string $establishmentId, string $levelId): ?array;
}
