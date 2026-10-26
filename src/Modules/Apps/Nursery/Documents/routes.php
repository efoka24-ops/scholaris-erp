<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Nursery\Documents\Http\DocumentController;
use Scholaris\Modules\Apps\Nursery\Support\NurseryRoutes;

return static function (NurseryRoutes $routes): void {
    $routes->get('/documents', [DocumentController::class, 'index']);
    $routes->post('/progress-books/{id}/document', [DocumentController::class, 'progressBook']);
    $routes->post('/pickup/authorized-persons/{id}/card', [DocumentController::class, 'pickupCard']);
    $routes->post('/register/document', [DocumentController::class, 'exitRegister']);
    $routes->get('/portal/documents', [DocumentController::class, 'portal']);
};
