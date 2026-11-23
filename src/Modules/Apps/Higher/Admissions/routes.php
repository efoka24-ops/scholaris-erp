<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Higher\Admissions\Http\ApplicationController;
use Scholaris\Modules\Apps\Higher\Admissions\Http\CampaignController;
use Scholaris\Modules\Apps\Higher\Admissions\Http\CandidateController;
use Scholaris\Modules\Apps\Higher\Admissions\Http\PublicLinkController;
use Scholaris\Modules\Apps\Higher\Support\HigherRoutes;

/**
 * Admissions (ADM) : campagnes, candidatures, pièces, frais de dossier, décisions, concours, transferts, statistiques.
 * Toutes les routes passent par HigherRoutes (préfixe /higher ; WriteGate avant chaque écriture).
 */
return static function (HigherRoutes $routes): void {
    $routes->get('/admissions/campaigns', [CampaignController::class, 'index']);
    $routes->post('/admissions/campaigns', [CampaignController::class, 'create']);
    $routes->get('/admissions/campaigns/{id}', [CampaignController::class, 'show']);
    $routes->patch('/admissions/campaigns/{id}', [CampaignController::class, 'update']);
    $routes->post('/admissions/campaigns/{id}/open', [CampaignController::class, 'open']);
    $routes->post('/admissions/campaigns/{id}/close', [CampaignController::class, 'close']);
    $routes->post('/admissions/campaigns/{id}/cancel', [CampaignController::class, 'cancel']);
    $routes->put('/admissions/campaigns/{id}/jury', [CampaignController::class, 'jury']);
    $routes->post('/admissions/campaigns/{id}/ranking', [CampaignController::class, 'rank']);
    $routes->get('/admissions/campaigns/{id}/statistics', [CampaignController::class, 'statistics']);
    $routes->get('/admissions/campaigns/{id}/contest-sessions', [CampaignController::class, 'sessions']);
    $routes->post('/admissions/campaigns/{id}/contest-sessions', [CampaignController::class, 'createSession']);
    $routes->post('/admissions/contest-sessions/{id}/convocations', [CampaignController::class, 'convoke']);

    $routes->get('/admissions/public-link', [PublicLinkController::class, 'show']);
    $routes->put('/admissions/public-link', [PublicLinkController::class, 'update']);
    $routes->get('/admissions/open-campaigns', [CandidateController::class, 'openCampaigns']);
    $routes->get('/admissions/my-applications', [CandidateController::class, 'mine']);
    $routes->get('/admissions/applications/{id}/follow-up', [CandidateController::class, 'followUp']);
    $routes->get('/admissions/applications', [ApplicationController::class, 'index']);
    $routes->post('/admissions/applications', [ApplicationController::class, 'create']);
    $routes->get('/admissions/applications/{id}', [ApplicationController::class, 'show']);
    $routes->post('/admissions/applications/{id}/documents', [ApplicationController::class, 'attachDocument']);
    $routes->post('/admissions/applications/{id}/documents/{documentId}/file', [ApplicationController::class, 'uploadDocument']);
    $routes->get('/admissions/applications/{id}/documents/{documentId}/file', [ApplicationController::class, 'downloadDocument']);
    $routes->post('/admissions/applications/{id}/documents/{documentId}/verify', [ApplicationController::class, 'verifyDocument']);
    $routes->post('/admissions/applications/{id}/documents/{documentId}/reject', [ApplicationController::class, 'rejectDocument']);
    $routes->post('/admissions/applications/{id}/submit', [ApplicationController::class, 'submit']);
    $routes->post('/admissions/applications/{id}/fee-payment', [ApplicationController::class, 'payFee']);
    $routes->post('/admissions/applications/{id}/score', [ApplicationController::class, 'score']);
    $routes->post('/admissions/applications/{id}/decision', [ApplicationController::class, 'decide']);
    $routes->post('/admissions/applications/{id}/withdraw', [ApplicationController::class, 'withdraw']);
    $routes->put('/admissions/applications/{id}/equivalences', [ApplicationController::class, 'equivalences']);
};
