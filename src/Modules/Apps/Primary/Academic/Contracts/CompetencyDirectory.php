<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Academic\Contracts;

/** Compétences du référentiel pour les autres sous-modules Primary (feuille de compétences, bulletin). */
interface CompetencyDirectory
{
    /** @return list<CompetencyInfo> compétences actives du niveau pour l'année, triées par domaine puis rang */
    public function forLevel(string $tenantId, string $establishmentId, string $academicYearId, string $levelStructureId): array;

    /**
     * @return array<string, array{code: string, label_fr: string, label_en: ?string, sort_order: int}> domaines de l'année par identifiant
     */
    public function domainsOf(string $tenantId, string $establishmentId, string $academicYearId): array;

    public function find(string $tenantId, string $establishmentId, string $competencyId): ?CompetencyInfo;

    /**
     * @param list<string> $ids
     * @return array<string, CompetencyInfo> par identifiant ; les identifiants inconnus sont absents
     */
    public function findMany(string $tenantId, string $establishmentId, array $ids): array;
}
