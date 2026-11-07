<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Admissions\Contracts;

use Scholaris\Modules\Apps\Higher\Support\Perimeter;
use Scholaris\Modules\Apps\Higher\Support\StatisticsFilter;

/**
 * Entonnoir des candidatures par école et filière, en une requête d'agrégat (tableaux de bord PIL). Aucune donnée nominative.
 * Le périmètre est appliqué ici ; la période filtre la date de dépôt (`submitted_at`).
 */
interface AdmissionStatistics
{
    /**
     * `applications` : dossiers déposés (hors brouillon) ; `admitted` : admis, admis sous condition et inscrits ; `enrolled` : inscrits.
     *
     * @return list<array{school_id: string, program_id: string, applications: int, admitted: int, enrolled: int}>
     */
    public function funnel(string $tenantId, string $establishmentId, Perimeter $perimeter, StatisticsFilter $filter): array;
}
