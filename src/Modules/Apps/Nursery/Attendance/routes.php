<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Nursery\Attendance\Http\CallController;
use Scholaris\Modules\Apps\Nursery\Support\NurseryRoutes;

return static function (NurseryRoutes $routes): void {
    $routes->get('/sections/{id}/roll', [CallController::class, 'roll']);
    $routes->post('/sections/{id}/call', [CallController::class, 'call']);
    $routes->post('/children/{id}/arrival', [CallController::class, 'arrival']);
};
