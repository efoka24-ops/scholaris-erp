<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Secondary\OfficialExams\Http\OfficialExamsController;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryRoutes;

/** Sessions et candidats aux examens officiels : toute route traverse la garde du module official_exams. */
return static function (SecondaryRoutes $routes): void {
    $exams = $routes->module('official_exams');
    $exams->get('/official-exams', [OfficialExamsController::class, 'sessions']);
    $exams->post('/official-exams', [OfficialExamsController::class, 'createSession']);
    $exams->get('/official-exams/{session_id}', [OfficialExamsController::class, 'showSession']);
    $exams->patch('/official-exams/{session_id}', [OfficialExamsController::class, 'updateSession']);
    $exams->get('/official-exams/{session_id}/candidates', [OfficialExamsController::class, 'candidates']);
    $exams->post('/official-exams/{session_id}/candidates/generate', [OfficialExamsController::class, 'generate']);
    $exams->post('/official-exams/{session_id}/candidates', [OfficialExamsController::class, 'addCandidate']);
    $exams->patch('/official-exams/candidates/{id}', [OfficialExamsController::class, 'updateCandidate']);
    $exams->delete('/official-exams/candidates/{id}', [OfficialExamsController::class, 'removeCandidate']);
    $exams->post('/official-exams/candidates/{id}/photo', [OfficialExamsController::class, 'setPhoto']);
    $exams->get('/official-exams/candidates/{id}/photo', [OfficialExamsController::class, 'photo']);
    $exams->delete('/official-exams/candidates/{id}/photo', [OfficialExamsController::class, 'removePhoto']);
    $exams->get('/official-exams/{session_id}/candidates/export', [OfficialExamsController::class, 'export']);
    $exams->post('/official-exams/{session_id}/results/import', [OfficialExamsController::class, 'importResults']);
    $exams->get('/official-exams/{session_id}/summary', [OfficialExamsController::class, 'summary']);
};
