<?php

declare(strict_types=1);

use Scholaris\Core\Http\RouteRegistry;
use Scholaris\Modules\Apps\PublicPrimary\Registry\Http\RegistryController;

return static function (RouteRegistry $routes): void {
    $routes->add('GET', '/public-primary/registry/profile', [RegistryController::class, 'profile']);
    $routes->add('PUT', '/public-primary/registry/profile', [RegistryController::class, 'saveProfile']);
    $routes->add('GET', '/public-primary/registry/territories/{id}/lineage', [RegistryController::class, 'territoryLineage']);
    $routes->add('GET', '/public-primary/registry/infrastructure', [RegistryController::class, 'latestInfrastructure']);
    $routes->add('POST', '/public-primary/registry/infrastructure', [RegistryController::class, 'createInfrastructure']);
    $routes->add('GET', '/public-primary/registry/infrastructure/{id}', [RegistryController::class, 'showInfrastructure']);
    $routes->add('PATCH', '/public-primary/registry/infrastructure/{id}', [RegistryController::class, 'updateInfrastructure']);
    $routes->add('POST', '/public-primary/registry/infrastructure/{id}/corrections', [RegistryController::class, 'correctInfrastructure']);
    $routes->add('POST', '/public-primary/registry/infrastructure/{id}/close', [RegistryController::class, 'closeInfrastructure']);
};