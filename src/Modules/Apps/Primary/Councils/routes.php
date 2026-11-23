<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Primary\Councils\Http\CouncilController;
use Scholaris\Modules\Apps\Primary\Support\PrimaryRoutes;

/** Conseil des maîtres de fin de trimestre. */
return static function (PrimaryRoutes $routes): void {
    $routes->get('/councils', [CouncilController::class, 'index']);
    $routes->post('/councils', [CouncilController::class, 'open']);
    $routes->get('/councils/{id}', [CouncilController::class, 'show']);
    $routes->patch('/councils/{id}/lines/{line_id}', [CouncilController::class, 'updateLine']);
    $routes->post('/councils/{id}/refresh', [CouncilController::class, 'refresh']);
    $routes->post('/councils/{id}/validate', [CouncilController::class, 'validate']);
    $routes->post('/councils/{id}/reopen', [CouncilController::class, 'reopen']);
};
