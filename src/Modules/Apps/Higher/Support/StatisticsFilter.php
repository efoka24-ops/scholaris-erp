<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Support;

/**
 * Filtres communs des contrats d'agrégation (tableaux de bord) : filière, année académique et période (dates AAAA-MM-JJ incluses).
 * Une valeur nulle ne filtre pas. Le périmètre (école, département, filière) est porté par Perimeter, pas par ce filtre.
 */
final class StatisticsFilter
{
    public function __construct(
        public readonly ?string $programId = null,
        public readonly ?string $academicYearId = null,
        public readonly ?string $from = null,
        public readonly ?string $to = null,
    ) {
    }
}
