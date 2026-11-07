<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Nursery\Messages\Http\MessageController;
use Scholaris\Modules\Apps\Nursery\Support\NurseryRoutes;

return static function (NurseryRoutes $routes): void {
    $routes->get('/messages', [MessageController::class, 'index']);
    $routes->post('/messages', [MessageController::class, 'store']);
};
