<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Secondary\Migration\Http\MigrationController;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryRoutes;

/**
 * Reprise de données d'un ancien logiciel (noyau, T977, E4-C) : jeux et modèles de fichiers, lots par fichier joint (simulation
 * obligatoire d'abord, rapport ligne par ligne), application seulement sans erreur. Droit d'usage et WriteGate par SecondaryRoutes.
 */
return static function (SecondaryRoutes $routes): void {
    $routes->get('/migration/kinds', [MigrationController::class, 'kinds']);
    $routes->get('/migration/templates/{kind}', [MigrationController::class, 'template']);
    $routes->get('/migration/batches', [MigrationController::class, 'index']);
    $routes->post('/migration/batches', [MigrationController::class, 'create']);
    $routes->get('/migration/batches/{id}', [MigrationController::class, 'show']);
    $routes->post('/migration/batches/{id}/apply', [MigrationController::class, 'apply']);
};
