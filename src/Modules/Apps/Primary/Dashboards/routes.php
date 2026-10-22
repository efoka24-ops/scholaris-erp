<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Primary\Dashboards\Http\DashboardController;
use Scholaris\Modules\Apps\Primary\Support\PrimaryRoutes;

/** Tableau de bord du directeur et contrôles préalables à la clôture d'année (lectures seules). */
return static function (PrimaryRoutes $routes): void {
    $routes->get('/dashboard', [DashboardController::class, 'show']);
    $routes->get('/closure/check', [DashboardController::class, 'closureCheck']);
};
