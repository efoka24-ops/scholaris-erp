<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Secondary\Services\Http\InfirmaryController;
use Scholaris\Modules\Apps\Secondary\Services\Http\ServicesController;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\Modules;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryRoutes;

/**
 * Services annexes (T972, T973) : chaque route déclare son module (Modules::OWNED_BY['Services']). Un module coupé après usage
 * garde ses lectures et refuse ses écritures (409, ModuleGate) ; un module jamais activé répond 409 en lecture comme en écriture.
 */
return static function (SecondaryRoutes $routes): void {
    $canteen = $routes->module(Modules::CANTEEN_TRANSPORT);
    $canteen->get('/services/canteen-transport/offers', [ServicesController::class, 'canteenOffers']);
    $canteen->post('/services/canteen-transport/offers', [ServicesController::class, 'canteenCreateOffer']);
    $canteen->patch('/services/canteen-transport/offers/{id}', [ServicesController::class, 'canteenUpdateOffer']);
    $canteen->get('/services/canteen-transport/subscriptions', [ServicesController::class, 'canteenSubscriptions']);
    $canteen->post('/services/canteen-transport/subscriptions', [ServicesController::class, 'canteenSubscribe']);
    $canteen->post('/services/canteen-transport/subscriptions/{id}/end', [ServicesController::class, 'canteenEnd']);

    $boarding = $routes->module(Modules::BOARDING);
    $boarding->get('/services/boarding/offers', [ServicesController::class, 'boardingOffers']);
    $boarding->post('/services/boarding/offers', [ServicesController::class, 'boardingCreateOffer']);
    $boarding->patch('/services/boarding/offers/{id}', [ServicesController::class, 'boardingUpdateOffer']);
    $boarding->get('/services/boarding/subscriptions', [ServicesController::class, 'boardingSubscriptions']);
    $boarding->post('/services/boarding/subscriptions', [ServicesController::class, 'boardingSubscribe']);
    $boarding->post('/services/boarding/subscriptions/{id}/end', [ServicesController::class, 'boardingEnd']);

    $infirmary = $routes->module(Modules::INFIRMARY);
    $infirmary->get('/services/infirmary/visits', [InfirmaryController::class, 'visits']);
    $infirmary->post('/services/infirmary/visits', [InfirmaryController::class, 'storeVisit']);
    $infirmary->get('/services/infirmary/visits/{id}', [InfirmaryController::class, 'visit']);
    $infirmary->get('/services/infirmary/records/{personId}', [InfirmaryController::class, 'record']);
    $infirmary->put('/services/infirmary/records/{personId}', [InfirmaryController::class, 'saveRecord']);
    $infirmary->get('/services/infirmary/access-log', [InfirmaryController::class, 'accessLog']);
    $infirmary->get('/services/infirmary/pe-exemptions', [InfirmaryController::class, 'exemptions']);
};
