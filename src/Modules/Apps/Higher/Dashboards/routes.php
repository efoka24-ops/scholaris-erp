<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Higher\Dashboards\Http\DashboardController;
use Scholaris\Modules\Apps\Higher\Support\HigherRoutes;

/**
 * Tableaux de bord (PIL) : enrolment, finance, grading, results, attendance, vacations, national, minesup. Lecture seule ;
 * l'export CSV/XLSX d'un tableau suit les mêmes filtres et masquages. Toutes les routes passent par HigherRoutes.
 */
return static function (HigherRoutes $routes): void {
    $routes->get('/dashboards/{kind}', [DashboardController::class, 'show']);
    $routes->get('/dashboards/{kind}/export', [DashboardController::class, 'export']);
};
