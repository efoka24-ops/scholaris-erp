<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Secondary\Dashboards\Http\DashboardController;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryRoutes;

/**
 * Pilotage (noyau, T970, T971) : tableaux de bord du chef d'établissement, du censeur et de l'intendant (objectifs O1 à O7), vue
 * réseau agrégée et masquée, registre des réclamations (O1), rapports exportables en fichier joint (CSV ou XLSX).
 */
return static function (SecondaryRoutes $routes): void {
    $routes->get('/dashboards/head', [DashboardController::class, 'head']);
    $routes->get('/dashboards/pedagogy', [DashboardController::class, 'pedagogy']);
    $routes->get('/dashboards/finance', [DashboardController::class, 'finance']);
    $routes->get('/dashboards/network', [DashboardController::class, 'network']);
    $routes->get('/dashboards/claims', [DashboardController::class, 'claims']);
    $routes->post('/dashboards/claims', [DashboardController::class, 'recordClaim']);
    $routes->post('/dashboards/claims/{id}/close', [DashboardController::class, 'closeClaim']);
    $routes->get('/dashboards/reports/{report}', [DashboardController::class, 'report']);
};
