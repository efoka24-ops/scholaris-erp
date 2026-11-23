<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\PublicSchools\Census\Http\CensusController;
use Scholaris\Modules\Apps\PublicSchools\Support\PublicRoutes;

return static function (PublicRoutes $routes): void {
    // STA : questionnaire pré-rempli, contrôles, circuit de validation (API-11), gel régional, correction motivée, DOC-23, export (Q3).
    $routes->get('/census', [CensusController::class, 'index']);
    $routes->post('/census', [CensusController::class, 'open']);
    $routes->get('/census/pending', [CensusController::class, 'pending']);
    $routes->get('/census/{id}', [CensusController::class, 'show']);
    $routes->patch('/census/{id}', [CensusController::class, 'edit']);
    $routes->post('/census/{id}/submit', [CensusController::class, 'submit']);
    $routes->post('/census/{id}/return', [CensusController::class, 'sendBack']);
    $routes->post('/census/{id}/corrections', [CensusController::class, 'correct']);
    $routes->post('/census/{id}/document', [CensusController::class, 'document']);
    $routes->get('/census/{id}/export', [CensusController::class, 'export']);
};
