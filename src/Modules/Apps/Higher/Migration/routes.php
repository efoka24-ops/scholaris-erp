<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Higher\Migration\Http\MigrationController;
use Scholaris\Modules\Apps\Higher\Support\HigherRoutes;

/**
 * Reprise de données (lot I4) : jeux et modèles de fichiers, lots (simulation d'abord, puis application et annulation avec double
 * authentification récente). Toutes les routes passent par HigherRoutes (préfixe /higher, WriteGate avant chaque écriture).
 */
return static function (HigherRoutes $routes): void {
    $routes->get('/migration/kinds', [MigrationController::class, 'kinds']);
    $routes->get('/migration/templates/{kind}', [MigrationController::class, 'template']);
    $routes->get('/migration/batches', [MigrationController::class, 'index']);
    $routes->post('/migration/batches', [MigrationController::class, 'create']);
    $routes->get('/migration/batches/{id}', [MigrationController::class, 'show']);
    $routes->post('/migration/batches/{id}/apply', [MigrationController::class, 'apply']);
    $routes->post('/migration/batches/{id}/revert', [MigrationController::class, 'revert']);
};
