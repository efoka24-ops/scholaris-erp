<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Nursery\Pickup\Http\AuthorizedPersonsController;
use Scholaris\Modules\Apps\Nursery\Pickup\Http\HandoverController;
use Scholaris\Modules\Apps\Nursery\Pickup\Http\RegisterController;
use Scholaris\Modules\Apps\Nursery\Pickup\Http\RestrictionController;
use Scholaris\Modules\Apps\Nursery\Support\NurseryRoutes;

return static function (NurseryRoutes $routes): void {
    $routes->get('/pickup/authorized-persons', [AuthorizedPersonsController::class, 'index']);
    $routes->post('/pickup/authorized-persons', [AuthorizedPersonsController::class, 'store']);
    $routes->post('/pickup/authorized-persons/{id}/revoke', [AuthorizedPersonsController::class, 'revoke']);
    $routes->get('/pickup/requests', [AuthorizedPersonsController::class, 'requests']);
    $routes->post('/pickup/requests/{id}/approve', [AuthorizedPersonsController::class, 'approve']);
    $routes->post('/pickup/requests/{id}/reject', [AuthorizedPersonsController::class, 'reject']);
    $routes->get('/pickup/restrictions', [RestrictionController::class, 'index']);
    $routes->post('/pickup/restrictions', [RestrictionController::class, 'store']);
    $routes->post('/pickup/restrictions/{id}/lift', [RestrictionController::class, 'lift']);
    $routes->post('/pickup/handovers', [HandoverController::class, 'handover']);
    $routes->post('/pickup/refusals', [HandoverController::class, 'refusal']);
    $routes->post('/pickup/holds', [HandoverController::class, 'hold']);
    $routes->post('/pickup/codes', [HandoverController::class, 'code']);
    $routes->get('/register', [RegisterController::class, 'register']);
    $routes->get('/sections/{id}/pickup-package', [RegisterController::class, 'package']);
};
