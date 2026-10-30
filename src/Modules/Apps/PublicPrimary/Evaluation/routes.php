<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\PublicPrimary\Evaluation\Http\EvaluationController;
use Scholaris\Modules\Apps\PublicPrimary\Evaluation\Http\ReportCardController;
use Scholaris\Modules\Apps\PublicSchools\Support\PublicRoutes;

return static function (PublicRoutes $routes): void {
    // EVA-03 à EVA-05, EVA-08 : saisie des critères (API-06), grille, remédiation (DOC-11, registre, fiche de suivi), notes chiffrées.
    $routes->put('/units/{id}/criterion-checks', [EvaluationController::class, 'saveChecks']);
    $routes->get('/units/{id}/grid', [EvaluationController::class, 'grid']);
    $routes->get('/units/{id}/remediation', [EvaluationController::class, 'remediationList']);
    $routes->get('/units/{id}/scores', [EvaluationController::class, 'scores']);
    $routes->put('/units/{id}/scores', [EvaluationController::class, 'saveScores']);
    $routes->get('/remediation-sessions', [EvaluationController::class, 'sessions']);
    $routes->post('/remediation-sessions', [EvaluationController::class, 'recordSession']);
    $routes->get('/remediation-sessions/summary', [EvaluationController::class, 'weeklySummary']);
    $routes->patch('/remediations/{id}', [EvaluationController::class, 'updateRemediation']);
    $routes->get('/classes/{id}/pupils/{person}/remediation-sheet', [EvaluationController::class, 'sheet']);

    // EVA-06, EVA-07 : bulletins DOC-08 à DOC-10, publication (API-07), rectificatif (RG-EVA-3), banque d'appréciations.
    $routes->post('/report-cards/generate', [ReportCardController::class, 'generate']);
    $routes->get('/report-cards', [ReportCardController::class, 'index']);
    $routes->get('/report-cards/{id}', [ReportCardController::class, 'show']);
    $routes->patch('/report-cards/{id}', [ReportCardController::class, 'appraise']);
    $routes->post('/report-cards/{id}/publish', [ReportCardController::class, 'publish']);
    $routes->post('/report-cards/{id}/rectify', [ReportCardController::class, 'rectify']);
    $routes->get('/appraisal-phrases', [ReportCardController::class, 'phrases']);
    $routes->post('/appraisal-phrases', [ReportCardController::class, 'addPhrase']);
};
