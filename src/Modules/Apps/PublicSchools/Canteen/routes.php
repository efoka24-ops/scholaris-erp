<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\PublicSchools\Canteen\Http\CanteenController;
use Scholaris\Modules\Apps\PublicSchools\Support\PublicRoutes;

return static function (PublicRoutes $routes): void {
    // CAN : repas par jour et par classe, synthèse mensuelle, stocks reçus et consommés, campagnes de santé avec consentements.
    $routes->get('/canteen/meals', [CanteenController::class, 'meals']);
    $routes->put('/canteen/meals/{date}', [CanteenController::class, 'saveMeals']);
    $routes->get('/canteen/summary', [CanteenController::class, 'summary']);
    $routes->get('/canteen/stock', [CanteenController::class, 'moves']);
    $routes->post('/canteen/stock', [CanteenController::class, 'addMove']);
    $routes->get('/canteen/stock/balance', [CanteenController::class, 'balance']);
    $routes->get('/health-campaigns', [CanteenController::class, 'campaigns']);
    $routes->post('/health-campaigns', [CanteenController::class, 'addCampaign']);
};
