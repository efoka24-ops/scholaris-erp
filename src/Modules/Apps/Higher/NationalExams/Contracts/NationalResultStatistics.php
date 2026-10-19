<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\NationalExams\Contracts;

use Scholaris\Modules\Apps\Higher\Support\Perimeter;
use Scholaris\Modules\Apps\Higher\Support\StatisticsFilter;

/**
 * Résultats des examens nationaux BTS et HND en agrégats SQL (tableaux de bord PIL). Aucune donnée nominative.
 * Le périmètre porte sur l'école, le département, la filière, le niveau et le groupe du candidat.
 */
interface NationalResultStatistics
{
    /**
     * `candidates` : candidats non retirés ; `resulted` : résultat importé ; `passed`, `failed`, `absent` : par résultat.
     *
     * @return list<array{exam_type: string, school_id: string, program_id: string, academic_year_id: string, candidates: int, resulted: int, passed: int, failed: int, absent: int}>
     */
    public function results(string $tenantId, string $establishmentId, Perimeter $perimeter, StatisticsFilter $filter): array;
}
