<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Primary\Documents\Http\DocumentsController;
use Scholaris\Modules\Apps\Primary\Support\PrimaryRoutes;

/** Documents administratifs du primaire et transfert sortant. */
return static function (PrimaryRoutes $routes): void {
    $routes->get('/documents/kinds', [DocumentsController::class, 'kinds']);
    $routes->post('/documents', [DocumentsController::class, 'issue']);
    $routes->post('/enrolments/{id}/transfer', [DocumentsController::class, 'transfer']);
};
