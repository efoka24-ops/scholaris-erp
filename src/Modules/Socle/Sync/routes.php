<?php

declare(strict_types=1);

use Scholaris\Core\Http\RouteRegistry;
use Scholaris\Modules\Socle\Sync\Http\BatchController;
use Scholaris\Modules\Socle\Sync\Http\DeviceController;
use Scholaris\Modules\Socle\Sync\Http\DevicePinController;

/**
 * Routes du module Sync (PP6). Le préfixe /api/v1 est ajouté par le registre ; toutes exigent l'authentification et
 * X-Establishment-Id (contrôle de périmètre du socle), puis une permission de l'établissement (sync.devices.manage,
 * sync.batches.submit). POST /sync/batches est en plus authentifiée par la signature de l'appareil.
 */
return static function (RouteRegistry $routes): void {
    $routes->add('GET', '/sync/devices', [DeviceController::class, 'index']);
    $routes->add('POST', '/sync/devices', [DeviceController::class, 'register']);
    $routes->add('POST', '/sync/devices/{id}/revoke', [DeviceController::class, 'revoke']);
    // PP7 (T608) : code personnel sur appareil partagé ; 5 échecs = appareil verrouillé et ordre d'effacement (423).
    $routes->add('PUT', '/sync/devices/{id}/pin', [DevicePinController::class, 'setPin']);
    $routes->add('POST', '/sync/devices/{id}/unlock', [DevicePinController::class, 'unlock']);

    $routes->add('POST', '/sync/batches', [BatchController::class, 'submit']);
    $routes->add('GET', '/sync/batches/{id}', [BatchController::class, 'show']);
};
