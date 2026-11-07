<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Higher\Grades\Http\GradesController;
use Scholaris\Modules\Apps\Higher\Grades\Http\StudentGradesController;
use Scholaris\Modules\Apps\Higher\Support\HigherRoutes;

/**
 * Notes (NOT) : évaluations par EC, grille (API-09), soumission (API-10), validation et verrouillage (API-11), publication,
 * anonymat, corrections (API-12), requêtes, suivi, statistiques, résultats publiés de l'étudiant (API-16).
 * Toutes les routes passent par HigherRoutes (préfixe /higher ; WriteGate avant chaque écriture).
 */
return static function (HigherRoutes $routes): void {
    $routes->get('/grades/settings', [GradesController::class, 'settings']);
    $routes->get('/grades/progress', [GradesController::class, 'progress']);
    $routes->post('/grades/sync', [GradesController::class, 'sync']);
    $routes->get('/grades/elements/{elementId}/statistics', [GradesController::class, 'elementStatistics']);

    $routes->get('/grade-assessments', [GradesController::class, 'index']);
    $routes->post('/grade-assessments', [GradesController::class, 'create']);
    $routes->get('/grade-assessments/{id}', [GradesController::class, 'show']);
    $routes->patch('/grade-assessments/{id}', [GradesController::class, 'update']);
    $routes->get('/grade-assessments/{id}/marks', [GradesController::class, 'grid']);
    $routes->put('/grade-assessments/{id}/marks', [GradesController::class, 'enter']);
    $routes->post('/grade-assessments/{id}/marks/import', [GradesController::class, 'import']);
    $routes->get('/grade-assessments/{id}/marks/template', [GradesController::class, 'template']);
    $routes->post('/grade-assessments/{id}/transition', [GradesController::class, 'transition']);
    $routes->post('/grade-assessments/{id}/submit', [GradesController::class, 'submit']);
    $routes->post('/grade-assessments/{id}/send-back', [GradesController::class, 'sendBack']);
    $routes->post('/grade-assessments/{id}/validate', [GradesController::class, 'validate']);
    $routes->post('/grade-assessments/{id}/lock', [GradesController::class, 'lock']);
    $routes->post('/grade-assessments/{id}/publish', [GradesController::class, 'publish']);
    $routes->post('/grade-assessments/{id}/anonymity/lift', [GradesController::class, 'liftAnonymity']);
    $routes->get('/grade-assessments/{id}/statistics', [GradesController::class, 'assessmentStatistics']);

    $routes->get('/grade-marks/{id}/events', [GradesController::class, 'events']);
    $routes->post('/grade-marks/{id}/changes', [GradesController::class, 'requestCorrection']);

    $routes->get('/grade-corrections', [GradesController::class, 'corrections']);
    $routes->get('/grade-corrections/{id}', [GradesController::class, 'correction']);
    $routes->get('/grade-corrections/{id}/attachment', [GradesController::class, 'correctionAttachment']);
    $routes->post('/grade-corrections/{id}/approve', [GradesController::class, 'approveCorrection']);
    $routes->post('/grade-corrections/{id}/reject', [GradesController::class, 'rejectCorrection']);

    $routes->get('/grade-queries', [GradesController::class, 'queries']);
    $routes->post('/grade-queries/{id}/respond', [GradesController::class, 'respondQuery']);

    $routes->get('/students/me/results', [StudentGradesController::class, 'results']);
    $routes->get('/students/me/grade-queries', [StudentGradesController::class, 'queries']);
    $routes->post('/students/me/grade-queries', [StudentGradesController::class, 'createQuery']);
};
