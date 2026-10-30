<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Primary\Dashboards\Http\AlertController;
use Scholaris\Modules\Apps\Primary\Dashboards\Http\DashboardController;
use Scholaris\Modules\Apps\Primary\Support\PrimaryRoutes;

/** Tableau de bord du directeur et contrôles préalables à la clôture d'année (lectures seules). */
return static function (PrimaryRoutes $routes): void {
    $routes->get('/dashboard', [DashboardController::class, 'show']);
    $routes->get('/closure/check', [DashboardController::class, 'closureCheck']);
    $routes->get('/closure/promotion-preview', [AlertController::class, 'promotionPreview']);
    $routes->get('/alerts', [AlertController::class, 'index']);
    $routes->post('/alerts/{id}/resolve', [AlertController::class, 'resolve']);
    $routes->get('/settings/defaults', [AlertController::class, 'defaults']);
};
