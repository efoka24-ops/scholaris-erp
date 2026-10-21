<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\PublicSchools\Pupils\Http\ClassController;
use Scholaris\Modules\Apps\PublicSchools\Support\PublicRoutes;

return static function (PublicRoutes $routes): void {
    $routes->post('/classes', [ClassController::class, 'create']);
    $routes->get('/classes', [ClassController::class, 'index']);
    $routes->get('/classes/{id}', [ClassController::class, 'show']);
};
