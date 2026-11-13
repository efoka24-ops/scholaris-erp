<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Primary\Exams\Http\ExamController;
use Scholaris\Modules\Apps\Primary\Support\PrimaryRoutes;

/** Examen de fin de cycle (CEP, FSLC). */
return static function (PrimaryRoutes $routes): void {
    $routes->get('/exam-catalogue', [ExamController::class, 'catalogue']);
    $routes->get('/exam-sessions', [ExamController::class, 'index']);
    $routes->post('/exam-sessions', [ExamController::class, 'store']);
    $routes->get('/exam-sessions/{id}', [ExamController::class, 'show']);
    $routes->patch('/exam-sessions/{id}', [ExamController::class, 'update']);
    $routes->post('/exam-sessions/{id}/status', [ExamController::class, 'status']);
    $routes->get('/exam-sessions/{id}/candidates', [ExamController::class, 'candidates']);
    $routes->post('/exam-sessions/{id}/candidates', [ExamController::class, 'addCandidate']);
    $routes->post('/exam-sessions/{id}/candidates/enrol-level', [ExamController::class, 'enrolLevel']);
    $routes->post('/exam-sessions/{id}/numbers', [ExamController::class, 'numbers']);
    $routes->put('/exam-sessions/{id}/results', [ExamController::class, 'results']);
    $routes->post('/exam-sessions/{id}/results/import', [ExamController::class, 'import']);
    $routes->post('/exam-sessions/{id}/results/publish', [ExamController::class, 'publish']);
    $routes->post('/exam-sessions/{id}/invoices', [ExamController::class, 'invoice']);
    $routes->get('/exam-sessions/{id}/export', [ExamController::class, 'export']);
    $routes->patch('/exam-candidates/{id}', [ExamController::class, 'updateCandidate']);
    $routes->post('/exam-candidates/{id}/withdraw', [ExamController::class, 'withdraw']);
    $routes->get('/exam-candidates/{id}/sheet', [ExamController::class, 'sheet']);
    $routes->get('/family/children/{id}/exams', [ExamController::class, 'family']);
};
