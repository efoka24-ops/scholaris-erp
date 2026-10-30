<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Primary\Attendance\Http\SetupController;
use Scholaris\Modules\Apps\Primary\Support\PrimaryRoutes;

/** Démarrage de l'application primaire et appel par demi-journée. */
return static function (PrimaryRoutes $routes): void {
    $routes->get('/setup', [SetupController::class, 'status']);
    $routes->post('/setup/apply', [SetupController::class, 'apply']);
};
