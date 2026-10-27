<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\PublicNursery\Gate\Http\GateController;
use Scholaris\Modules\Apps\PublicNursery\Support\NurseryRoutes;

return static function (NurseryRoutes $routes): void {
    $routes->post('/pickup/arrivals', [GateController::class, 'arrival']);
    $routes->post('/pickup/holds', [GateController::class, 'hold']);
    $routes->get('/register', [GateController::class, 'register']);
    $routes->get('/register/audit', [GateController::class, 'audit']);
};
