<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Setup\Contracts;

/**
 * Coefficients et volumes horaires en vigueur (contrat interne, fourni par Setup) : stratégie de calcul, emplois du temps,
 * cahier de textes. Pour une série, les lignes propres à la série remplacent celles du niveau pour la même matière.
 */
interface CoefficientBook
{
    /** @return list<CoefficientLine> */
    public function forLevel(string $tenantId, string $establishmentId, string $academicYearId, string $levelCode, ?string $streamId = null): array;
}
