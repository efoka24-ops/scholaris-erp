<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Nursery\Portal\Http\PortalController;
use Scholaris\Modules\Apps\Nursery\Support\NurseryRoutes;

return static function (NurseryRoutes $routes): void {
    $routes->get('/portal/children', [PortalController::class, 'children']);
    $routes->get('/portal/pickups', [PortalController::class, 'pickups']);
    $routes->post('/portal/pickups', [PortalController::class, 'addPickup']);
    $routes->post('/portal/pickups/{id}/revoke', [PortalController::class, 'revokePickup']);
    $routes->post('/portal/pickup-codes', [PortalController::class, 'issueCode']);
    $routes->put('/portal/children/{id}/allergies', [PortalController::class, 'declareAllergy']);
};
