<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Higher\Deliberation\Http\JuryController;
use Scholaris\Modules\Apps\Higher\Deliberation\Http\ResultController;
use Scholaris\Modules\Apps\Higher\Support\HigherRoutes;

/**
 * Délibération (DEL) : jurys, simulation (API-13), points de jury, rattrapage, documents du jury, décisions de fin d'année, relevés.
 * La séance (décisions, clôture, signature du PV API-14, publication API-15, réouverture) passe par les routes du socle
 * (/deliberations, /results/publish), gardées par HigherDeliberationGuard. Toutes ces routes passent par HigherRoutes (WriteGate).
 */
return static function (HigherRoutes $routes): void {
    $routes->get('/deliberation/juries', [JuryController::class, 'index']);
    $routes->post('/deliberation/juries', [JuryController::class, 'create']);
    $routes->get('/deliberation/juries/{id}', [JuryController::class, 'show']);
    $routes->patch('/deliberation/juries/{id}', [JuryController::class, 'update']);
    $routes->post('/deliberation/juries/{id}/attendance', [JuryController::class, 'attendance']);
    $routes->post('/deliberation/juries/{id}/simulate', [JuryController::class, 'simulate']);
    $routes->post('/deliberation/juries/{id}/points', [JuryController::class, 'points']);
    $routes->get('/deliberation/juries/{id}/resit-list', [JuryController::class, 'resitList']);
    $routes->post('/deliberation/juries/{id}/resit-list', [JuryController::class, 'convokeResit']);
    $routes->post('/deliberation/juries/{id}/documents', [JuryController::class, 'document']);
    $routes->get('/deliberation/rules-template', [JuryController::class, 'rulesTemplate']);
    $routes->get('/deliberation/jury-portal', [JuryController::class, 'portal']);

    $routes->get('/deliberation/year-decisions', [ResultController::class, 'yearIndex']);
    $routes->post('/deliberation/year-decisions/preview', [ResultController::class, 'yearPreview']);
    $routes->post('/deliberation/year-decisions', [ResultController::class, 'yearRecord']);
    $routes->get('/deliberation/transcripts', [ResultController::class, 'transcript']);
    $routes->post('/deliberation/transcripts', [ResultController::class, 'issueTranscript']);
    $routes->get('/deliberation/my-results', [ResultController::class, 'mine']);
};
