<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Higher\Curriculum\Http\CurriculumController;
use Scholaris\Modules\Apps\Higher\Support\HigherRoutes;

/**
 * Maquettes (MAQ) : versions par filière, niveau et année ; UE et EC ; contrôle, publication, nouvelle version, copie, export.
 * Toutes les routes passent par HigherRoutes (préfixe /higher ; WriteGate avant chaque écriture).
 */
return static function (HigherRoutes $routes): void {
    $routes->get('/curricula', [CurriculumController::class, 'index']);
    $routes->post('/curricula', [CurriculumController::class, 'create']);
    $routes->get('/curricula/{id}', [CurriculumController::class, 'show']);
    $routes->patch('/curricula/{id}', [CurriculumController::class, 'update']);
    $routes->get('/curricula/{id}/check', [CurriculumController::class, 'check']);
    $routes->get('/curricula/{id}/export', [CurriculumController::class, 'export']);
    $routes->post('/curricula/{id}/publish', [CurriculumController::class, 'publish']);
    $routes->post('/curricula/{id}/unpublish', [CurriculumController::class, 'unpublish']);
    $routes->post('/curricula/{id}/versions', [CurriculumController::class, 'newVersion']);
    $routes->post('/curricula/{id}/copy', [CurriculumController::class, 'copy']);
    $routes->post('/curricula/{id}/units', [CurriculumController::class, 'addUnit']);
    $routes->patch('/curriculum-units/{id}', [CurriculumController::class, 'updateUnit']);
    $routes->delete('/curriculum-units/{id}', [CurriculumController::class, 'removeUnit']);
    $routes->post('/curriculum-units/{id}/elements', [CurriculumController::class, 'addElement']);
    $routes->patch('/curriculum-elements/{id}', [CurriculumController::class, 'updateElement']);
    $routes->delete('/curriculum-elements/{id}', [CurriculumController::class, 'removeElement']);
};
