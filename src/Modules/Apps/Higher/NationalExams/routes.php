<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Higher\NationalExams\Http\CandidateController;
use Scholaris\Modules\Apps\Higher\NationalExams\Http\SessionController;
use Scholaris\Modules\Apps\Higher\Support\HigherRoutes;

/**
 * Examens nationaux BTS et HND (EXN) : sessions, liste des candidats (RG-EXN-1), export MINESUP (API-17, 2FA, alerte DG),
 * convocations, import des résultats, taux de réussite, documents DOC-12 et DOC-17. Toutes les routes passent par HigherRoutes.
 */
return static function (HigherRoutes $routes): void {
    $routes->get('/national-sessions', [SessionController::class, 'index']);
    $routes->post('/national-sessions', [SessionController::class, 'create']);
    $routes->get('/national-sessions/{id}', [SessionController::class, 'show']);
    $routes->patch('/national-sessions/{id}', [SessionController::class, 'update']);
    $routes->get('/national-sessions/{id}/eligibility', [SessionController::class, 'eligibility']);
    $routes->get('/national-sessions/{id}/events', [SessionController::class, 'events']);
    $routes->get('/national-sessions/{id}/statistics', [SessionController::class, 'statistics']);
    $routes->get('/national-exams/success-rates', [SessionController::class, 'successRates']);

    $routes->get('/national-sessions/{id}/candidates', [CandidateController::class, 'index']);
    $routes->post('/national-sessions/{id}/candidates', [CandidateController::class, 'add']);
    $routes->post('/national-sessions/{id}/candidates/generate', [CandidateController::class, 'generate']);
    $routes->post('/national-sessions/{id}/candidates/export', [CandidateController::class, 'export']);
    $routes->post('/national-sessions/{id}/convocations', [CandidateController::class, 'convene']);
    $routes->post('/national-sessions/{id}/results/import', [CandidateController::class, 'importResults']);
    $routes->patch('/national-candidates/{id}', [CandidateController::class, 'update']);
    $routes->post('/national-candidates/{id}/withdraw', [CandidateController::class, 'withdraw']);
    $routes->post('/national-candidates/{id}/documents', [CandidateController::class, 'document']);
};
