<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Grades\Contracts;

use Scholaris\Modules\Apps\Higher\Support\Perimeter;
use Scholaris\Modules\Apps\Higher\Support\StatisticsFilter;

/**
 * Avancement de la saisie et réussite par élément constitutif (EC), en agrégats SQL (tableaux de bord PIL). Aucune donnée
 * nominative. Le périmètre porte sur l'école, le département, la filière, le niveau et le groupe de l'évaluation.
 */
interface GradingStatistics
{
    /**
     * Avancement par EC : évaluations par état, évaluations en retard de saisie, cellules attendues et saisies.
     *
     * @return list<array{school_id: string, program_id: string, unit_id: string, element_id: string, element_code: string, element_title: string, assessments: int, draft: int, submitted: int, validated: int, locked: int, published: int, overdue: int, expected: int, entered: int}>
     */
    public function progress(string $tenantId, string $establishmentId, Perimeter $perimeter, StatisticsFilter $filter, string $today): array;

    /**
     * Réussite par EC pour une session (`normal` ou `retake`) : étudiants notés et étudiants à 10/20 au moins (moyenne pondérée des
     * évaluations verrouillées ou publiées, notes saisies seulement, ramenées sur 20).
     *
     * @return list<array{school_id: string, program_id: string, level_id: string, unit_id: string, element_id: string, element_code: string, element_title: string, students: int, passed: int}>
     */
    public function success(string $tenantId, string $establishmentId, Perimeter $perimeter, StatisticsFilter $filter, string $session): array;
}
