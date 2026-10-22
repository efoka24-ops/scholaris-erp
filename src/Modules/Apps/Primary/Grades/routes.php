<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Primary\Grades\Http\GradesController;
use Scholaris\Modules\Apps\Primary\Support\PrimaryRoutes;

/** Évaluation du primaire : feuille de compétences par classe et période, corrections approuvées, conduite. */
return static function (PrimaryRoutes $routes): void {
    $routes->get('/classes/{id}/competency-sheet', [GradesController::class, 'sheet']);
    $routes->put('/classes/{id}/competency-sheet', [GradesController::class, 'saveSheet']);
    $routes->post('/classes/{id}/competency-sheet/lock', [GradesController::class, 'lock']);
    $routes->post('/classes/{id}/competency-sheet/publish', [GradesController::class, 'publish']);

    $routes->get('/competency-corrections', [GradesController::class, 'corrections']);
    $routes->post('/competency-marks/{id}/corrections', [GradesController::class, 'requestCorrection']);
    $routes->get('/competency-marks/{id}/history', [GradesController::class, 'history']);
    $routes->post('/competency-corrections/{id}/approve', [GradesController::class, 'approve']);
    $routes->post('/competency-corrections/{id}/reject', [GradesController::class, 'reject']);

    $routes->get('/classes/{id}/conduct', [GradesController::class, 'conduct']);
    $routes->put('/classes/{id}/conduct', [GradesController::class, 'saveConduct']);
};
