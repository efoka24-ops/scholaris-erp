<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\PublicSchools\Inspection\Http\InspectionController;
use Scholaris\Modules\Apps\PublicSchools\Support\PublicRoutes;

return static function (PublicRoutes $routes): void {
    // INS : grilles paramétrables, planning, visites (hors ligne : sync.handlers.public.inspection_visit), DOC-26, frais illégaux.
    $routes->get('/inspection/grids', [InspectionController::class, 'grids']);
    $routes->post('/inspection/grids', [InspectionController::class, 'saveGrid']);
    $routes->post('/inspection/grids/import-example', [InspectionController::class, 'importExample']);
    $routes->get('/inspection/scale', [InspectionController::class, 'scale']);
    $routes->get('/inspection/planning', [InspectionController::class, 'planning']);
    $routes->get('/inspection/visits', [InspectionController::class, 'visits']);
    $routes->post('/inspection/visits', [InspectionController::class, 'plan']);
    $routes->get('/inspection/visits/{id}', [InspectionController::class, 'show']);
    $routes->put('/inspection/visits/{id}/report', [InspectionController::class, 'report']);
    $routes->post('/inspection/visits/{id}/document', [InspectionController::class, 'document']);
    $routes->get('/inspection/recommendations', [InspectionController::class, 'recommendations']);
    $routes->get('/inspection/fee-reports', [InspectionController::class, 'feeReports']);
    $routes->post('/inspection/fee-reports', [InspectionController::class, 'reportFee']);
    $routes->post('/inspection/fee-reports/{id}/handle', [InspectionController::class, 'handleFee']);
};
