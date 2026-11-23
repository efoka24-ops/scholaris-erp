<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\PublicSchools\Staff\Http\StaffController;
use Scholaris\Modules\Apps\PublicSchools\Support\PublicRoutes;

return static function (PublicRoutes $routes): void {
    // PER : personnel de l'école (EX-PER-01), formations (EX-PER-03), mutations dans le territoire (EX-PER-02), besoins (EX-PER-04).
    $routes->get('/staff', [StaffController::class, 'index']);
    $routes->get('/staff/needs', [StaffController::class, 'needs']);
    $routes->get('/staff/{id}/profile', [StaffController::class, 'showProfile']);
    $routes->put('/staff/{id}/profile', [StaffController::class, 'saveProfile']);
    $routes->get('/staff/{id}/trainings', [StaffController::class, 'trainings']);
    $routes->post('/staff/{id}/trainings', [StaffController::class, 'addTraining']);
    $routes->get('/staff/{id}/moves', [StaffController::class, 'moves']);
    $routes->post('/staff/{id}/moves', [StaffController::class, 'move']);
};
