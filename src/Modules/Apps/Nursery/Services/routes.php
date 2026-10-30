<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Nursery\Services\Http\ServiceController;
use Scholaris\Modules\Apps\Nursery\Support\NurseryRoutes;

return static function (NurseryRoutes $routes): void {
    $routes->get('/services/plans', [ServiceController::class, 'plans']);
    $routes->post('/services/plans', [ServiceController::class, 'createPlan']);
    $routes->patch('/services/plans/{id}', [ServiceController::class, 'updatePlan']);
    $routes->get('/services/subscriptions', [ServiceController::class, 'subscriptions']);
    $routes->post('/services/subscriptions', [ServiceController::class, 'subscribe']);
    $routes->post('/services/subscriptions/{id}/end', [ServiceController::class, 'end']);
    $routes->post('/services/subscriptions/{id}/invoice', [ServiceController::class, 'invoice']);
    $routes->get('/services/subscriptions/{id}/invoices', [ServiceController::class, 'invoices']);
    $routes->post('/services/invoices', [ServiceController::class, 'invoiceAll']);
    $routes->get('/services/attendance', [ServiceController::class, 'roster']);
    $routes->post('/services/attendance', [ServiceController::class, 'record']);
    $routes->get('/portal/services', [ServiceController::class, 'portal']);
};
