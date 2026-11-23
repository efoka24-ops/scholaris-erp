<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Syllabus\Contracts;

/** Couverture des programmes d'une classe (contrat interne, fourni par Syllabus) : conseils de classe, tableaux de bord. */
interface CoverageReader
{
    /** @return list<CoverageLine> une ligne par affectation active de la classe pour l'année */
    public function coverage(string $tenantId, string $establishmentId, string $academicYearId, string $classId): array;
}
