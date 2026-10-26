<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Primary\Pickup\Http\PickupController;
use Scholaris\Modules\Apps\Primary\Support\PrimaryRoutes;

/** Sortie sécurisée (activée par niveau, désactivée par défaut). */
return static function (PrimaryRoutes $routes): void {
    $routes->get('/classes/{id}/pickups', [PickupController::class, 'day']);
    $routes->post('/classes/{id}/pickups/handover', [PickupController::class, 'handover']);
    $routes->post('/classes/{id}/pickups/refusals', [PickupController::class, 'refuse']);
};
