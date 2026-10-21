<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\PublicNursery\Pickup\Http\AuthorizedPersonsController;
use Scholaris\Modules\Apps\PublicNursery\Pickup\Http\CustodyRestrictionController;
use Scholaris\Modules\Apps\PublicNursery\Pickup\Http\HandoverController;
use Scholaris\Modules\Apps\PublicNursery\Portal\Http\PortalPickupController;
use Scholaris\Modules\Apps\PublicNursery\Support\NurseryRoutes;

return static function (NurseryRoutes $routes): void {
    $routes->get('/pickup/authorized-persons', [AuthorizedPersonsController::class, 'index']);
    $routes->post('/pickup/authorized-persons', [AuthorizedPersonsController::class, 'store']);
    $routes->post('/pickup/authorized-persons/{id}/revoke', [AuthorizedPersonsController::class, 'revoke']);
    $routes->get('/pickup/restrictions', [CustodyRestrictionController::class, 'index']);
    $routes->post('/pickup/restrictions', [CustodyRestrictionController::class, 'store']);
    $routes->post('/pickup/restrictions/{id}/lift', [CustodyRestrictionController::class, 'lift']);
    $routes->post('/pickup/handovers', [HandoverController::class, 'store']);
    $routes->get('/portal/pickups', [PortalPickupController::class, 'index']);
    $routes->post('/portal/pickups', [PortalPickupController::class, 'store']);
    $routes->post('/portal/pickups/{id}/revoke', [PortalPickupController::class, 'revoke']);
};
