<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\PublicSchools\Messages\Http\MessageController;
use Scholaris\Modules\Apps\PublicSchools\Support\PublicRoutes;

return static function (PublicRoutes $routes): void {
    // COM : SMS à une classe, un niveau ou l'école (EX-COM-01), journal des envois, annonces territoriales (EX-COM-05).
    $routes->post('/messages', [MessageController::class, 'send']);
    $routes->get('/messages', [MessageController::class, 'index']);
    $routes->post('/announcements', [MessageController::class, 'announce']);
};
