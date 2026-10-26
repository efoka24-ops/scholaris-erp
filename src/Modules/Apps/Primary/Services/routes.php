<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Primary\Services\Http\ServiceController;
use Scholaris\Modules\Apps\Primary\Support\PrimaryRoutes;

/** Services aux familles (cantine, garderie, transport). */
return static function (PrimaryRoutes $routes): void {
    $routes->get('/service-plans', [ServiceController::class, 'index']);
    $routes->post('/service-plans', [ServiceController::class, 'store']);
    $routes->patch('/service-plans/{id}', [ServiceController::class, 'update']);
    $routes->get('/service-plans/{id}/subscriptions', [ServiceController::class, 'subscriptions']);
    $routes->post('/service-plans/{id}/subscriptions', [ServiceController::class, 'subscribe']);
    $routes->post('/service-plans/{id}/invoice', [ServiceController::class, 'invoice']);
    $routes->post('/service-subscriptions/{id}/end', [ServiceController::class, 'end']);
};
