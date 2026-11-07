<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Vacations\Contracts;

use Scholaris\Modules\Apps\Higher\Support\Perimeter;
use Scholaris\Modules\Apps\Higher\Support\StatisticsFilter;

/**
 * Heures et coût des vacations par département et par mois en agrégats SQL (tableaux de bord PIL). Aucune donnée nominative.
 * Seuls les états validé par l'école, approuvé et payé comptent (ni brouillon ni rejet). La période filtre le mois du relevé
 * (les dates AAAA-MM-JJ du filtre sont ramenées au mois). Montants en XAF.
 */
interface VacationStatistics
{
    /**
     * `minutes` : durée enseignée ; `hetd_milli` : heures équivalent TD en millièmes ; `net` : montant net.
     *
     * @return list<array{school_id: string, department_id: string, month: string, statements: int, minutes: int, hetd_milli: int, gross_xaf: int, net_xaf: int, paid_xaf: int}>
     */
    public function byDepartmentMonth(string $tenantId, string $establishmentId, Perimeter $perimeter, StatisticsFilter $filter): array;
}
