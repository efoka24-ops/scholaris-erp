<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Attendance\Contracts;

use Scholaris\Modules\Apps\Higher\Support\Perimeter;
use Scholaris\Modules\Apps\Higher\Support\StatisticsFilter;

/**
 * Assiduité par groupe et par élément constitutif en agrégats SQL (tableaux de bord PIL), calculée sur les appels validés du socle
 * (séances rattachées à un EC). Aucune donnée nominative. Le périmètre porte sur le groupe de la séance ; la période filtre
 * la date de la séance.
 */
interface AssiduityStatistics
{
    /**
     * `records` : lignes d'appel ; `present`, `late`, `absent` : par code d'appel ; `unjustified` : absences non justifiées.
     *
     * @return list<array{group_id: string, element_id: string, label: string, sessions: int, records: int, present: int, late: int, absent: int, unjustified: int}>
     */
    public function byGroupElement(string $tenantId, string $establishmentId, Perimeter $perimeter, StatisticsFilter $filter): array;
}
