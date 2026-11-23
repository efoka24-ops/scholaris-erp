<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\PublicNursery\Documents\Http\PickupDocumentController;
use Scholaris\Modules\Apps\PublicNursery\Support\NurseryRoutes;

return static function (NurseryRoutes $routes): void {
    $routes->get('/documents', [PickupDocumentController::class, 'index']);
    $routes->post('/pickup/authorized-persons/{id}/card', [PickupDocumentController::class, 'card']);
    $routes->post('/register/document', [PickupDocumentController::class, 'register']);
};
