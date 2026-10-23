<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Primary\ReportCards\Http\ReportCardController;
use Scholaris\Modules\Apps\Primary\Support\PrimaryRoutes;

/** Bulletins trimestriels et récapitulatif annuel. */
return static function (PrimaryRoutes $routes): void {
    $routes->get('/councils/{id}/report-cards', [ReportCardController::class, 'index']);
    $routes->get('/councils/{id}/report-cards/preview', [ReportCardController::class, 'preview']);
    $routes->post('/councils/{id}/report-cards/issue', [ReportCardController::class, 'issue']);
    $routes->post('/councils/{id}/report-cards/publish', [ReportCardController::class, 'publish']);
    $routes->get('/report-cards/{id}', [ReportCardController::class, 'show']);
    $routes->post('/report-cards/{id}/rectify', [ReportCardController::class, 'rectify']);
    $routes->get('/family/children/{id}/report-cards', [ReportCardController::class, 'family']);
};
