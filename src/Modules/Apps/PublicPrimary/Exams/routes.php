<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\PublicPrimary\Exams\Http\ExamController;
use Scholaris\Modules\Apps\PublicPrimary\Exams\Http\ExamTerritoryController;
use Scholaris\Modules\Apps\PublicSchools\Support\PublicRoutes;

return static function (PublicRoutes $routes): void {
    // EXA : sessions nationales, liste de l'école et circuit (API-09), DOC-13 à DOC-17, NOT-08, résultats (API-10), attestations.
    $routes->get('/exams', [ExamController::class, 'sessions']);
    $routes->post('/exams', [ExamController::class, 'createSession']);
    $routes->get('/exams/{id}', [ExamController::class, 'show']);
    $routes->get('/exams/{id}/candidates', [ExamController::class, 'candidates']);
    $routes->post('/exams/{id}/candidates', [ExamController::class, 'add']);
    $routes->post('/exams/{id}/candidates/generate', [ExamController::class, 'generate']);
    $routes->post('/exams/{id}/candidates/validate', [ExamController::class, 'validate']);
    $routes->post('/exams/{id}/submit', [ExamController::class, 'submit']);
    $routes->post('/exams/{id}/return', [ExamController::class, 'sendBack']);
    $routes->get('/exams/{id}/export', [ExamController::class, 'export']);
    $routes->post('/exams/{id}/document', [ExamController::class, 'document']);
    $routes->post('/exams/{id}/birth-act-alerts', [ExamController::class, 'birthActAlerts']);
    $routes->post('/exams/{id}/results/import', [ExamController::class, 'import']);
    $routes->post('/exams/{id}/results/publish', [ExamController::class, 'publish']);
    $routes->get('/exams/{id}/results', [ExamController::class, 'results']);
    $routes->post('/exams/{id}/results/document', [ExamController::class, 'statement']);
    $routes->post('/exams/{id}/attestations', [ExamController::class, 'attestations']);
    // Délégation et direction des examens : consolidation d'un territoire, centres, jurys et vacations (décret 2010/1738).
    $routes->get('/exams/{id}/territories/{territory}/lists', [ExamTerritoryController::class, 'lists']);
    $routes->get('/exams/{id}/territories/{territory}/results', [ExamTerritoryController::class, 'results']);
    $routes->get('/exams/{id}/territories/{territory}/export', [ExamTerritoryController::class, 'export']);
    $routes->get('/exams/{id}/territories/{territory}/vacations', [ExamTerritoryController::class, 'vacations']);
    $routes->get('/exams/{id}/territories/{territory}/vacations/export', [ExamTerritoryController::class, 'vacationsExport']);
    $routes->get('/exams/{id}/centres', [ExamTerritoryController::class, 'centres']);
    $routes->post('/exams/{id}/centres', [ExamTerritoryController::class, 'createCentre']);
    $routes->post('/exams/{id}/centres/{centre}/assign', [ExamTerritoryController::class, 'assign']);
    $routes->get('/exams/{id}/officials', [ExamTerritoryController::class, 'officials']);
    $routes->post('/exams/{id}/officials', [ExamTerritoryController::class, 'addOfficial']);
    // Candidat : retrait motivé, validation après vérification de l'acte (RG-EXA-1), fiche DOC-14, signature DOC-16, dossier secondaire.
    $routes->post('/exam-candidates/{id}/withdraw', [ExamController::class, 'withdraw']);
    $routes->post('/exam-candidates/{id}/validate', [ExamController::class, 'validateCandidate']);
    $routes->post('/exam-candidates/{id}/sheet', [ExamController::class, 'sheet']);
    $routes->post('/exam-candidates/{id}/attestation/sign', [ExamController::class, 'sign']);
    $routes->post('/exam-candidates/{id}/secondary-transfer', [ExamController::class, 'createTransfer']);
    $routes->get('/exam-candidates/{id}/secondary-transfer', [ExamController::class, 'transfer']);
};
