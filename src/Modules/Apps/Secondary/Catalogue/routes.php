<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Secondary\Catalogue\Http\PlatformStreamController;
use Scholaris\Modules\Apps\Secondary\Catalogue\Http\StreamController;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryRoutes;

/** Table de référence des séries et spécialités : vue de l'établissement (noyau) et administration de la plateforme. */
return static function (SecondaryRoutes $routes): void {
    $routes->get('/catalogue/streams', [StreamController::class, 'index']);
    $routes->post('/catalogue/streams', [StreamController::class, 'store']);
    $routes->get('/catalogue/streams/{id}', [StreamController::class, 'show']);
    $routes->patch('/catalogue/streams/{id}', [StreamController::class, 'update']);

    $routes->platformGet('/streams', [PlatformStreamController::class, 'index']);
    $routes->platformPost('/streams', [PlatformStreamController::class, 'store']);
    $routes->platformGet('/streams/{id}', [PlatformStreamController::class, 'show']);
    $routes->platformPatch('/streams/{id}', [PlatformStreamController::class, 'update']);
};
