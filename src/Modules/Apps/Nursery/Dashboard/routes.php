<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Nursery\Dashboard\Http\DashboardController;
use Scholaris\Modules\Apps\Nursery\Support\NurseryRoutes;

return static function (NurseryRoutes $routes): void {
    $routes->get('/dashboard', [DashboardController::class, 'show']);
};
