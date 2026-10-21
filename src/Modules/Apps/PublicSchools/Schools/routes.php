<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\PublicSchools\Schools\Http\InfrastructureController;
use Scholaris\Modules\Apps\PublicSchools\Schools\Http\PhotoController;
use Scholaris\Modules\Apps\PublicSchools\Schools\Http\RegistryController;
use Scholaris\Modules\Apps\PublicSchools\Schools\Http\SchoolController;
use Scholaris\Modules\Apps\PublicSchools\Support\PublicRoutes;

return static function (PublicRoutes $routes): void {
    if ($routes->prefix() === PublicRoutes::PREFIX) {
        $routes->get('/schools/current', [SchoolController::class, 'current']);
        $routes->put('/schools/current', [SchoolController::class, 'update']);
        $routes->get('/schools/current/infrastructure', [InfrastructureController::class, 'index']);
        $routes->post('/schools/current/infrastructure', [InfrastructureController::class, 'create']);
        $routes->get('/schools/current/infrastructure/{id}', [InfrastructureController::class, 'show']);
        $routes->patch('/schools/current/infrastructure/{id}', [InfrastructureController::class, 'update']);
        $routes->post('/schools/current/infrastructure/{id}/close', [InfrastructureController::class, 'close']);
        $routes->post('/schools/current/infrastructure/{id}/corrections', [InfrastructureController::class, 'correct']);
        $routes->get('/schools/photos', [PhotoController::class, 'index']);
        $routes->post('/schools/photos', [PhotoController::class, 'create']);
        $routes->get('/schools/photos/{id}/content', [PhotoController::class, 'content']);

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
