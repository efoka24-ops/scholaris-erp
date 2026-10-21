<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\PublicSchools\Schools\Http\RegistryController;
use Scholaris\Modules\Apps\PublicSchools\Schools\Http\SchoolController;
use Scholaris\Modules\Apps\PublicSchools\Support\PublicRoutes;

return static function (PublicRoutes $routes): void {
    if ($routes->prefix() === PublicRoutes::PREFIX) {
        $routes->get('/schools/current', [SchoolController::class, 'current']);

        return;
    }

    $routes->get('/registry/profile', [RegistryController::class, 'profile']);
    $routes->put('/registry/profile', [RegistryController::class, 'saveProfile']);
    $routes->get('/registry/territories/{id}/lineage', [RegistryController::class, 'territoryLineage']);
    $routes->get('/registry/infrastructure', [RegistryController::class, 'latestInfrastructure']);
    $routes->post('/registry/infrastructure', [RegistryController::class, 'createInfrastructure']);
    $routes->get('/registry/infrastructure/{id}', [RegistryController::class, 'showInfrastructure']);
    $routes->patch('/registry/infrastructure/{id}', [RegistryController::class, 'updateInfrastructure']);
    $routes->post('/registry/infrastructure/{id}/corrections', [RegistryController::class, 'correctInfrastructure']);
    $routes->post('/registry/infrastructure/{id}/close', [RegistryController::class, 'closeInfrastructure']);
};
