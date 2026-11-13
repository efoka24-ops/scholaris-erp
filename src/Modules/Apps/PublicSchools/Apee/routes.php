<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\PublicSchools\Apee\Http\ApeeController;
use Scholaris\Modules\Apps\PublicSchools\Support\PublicRoutes;

return static function (PublicRoutes $routes): void {
    // APE : montant voté, encaissements et dépenses (API-14), reçus DOC-24, état financier DOC-25, exonérations, non-contributeurs (RG-APE-2).
    $routes->get('/apee/years', [ApeeController::class, 'years']);
    $routes->post('/apee/years', [ApeeController::class, 'vote']);
    $routes->get('/apee/families', [ApeeController::class, 'families']);
    $routes->post('/apee/entries', [ApeeController::class, 'record']);
    $routes->post('/apee/entries/{id}/proof', [ApeeController::class, 'attachProof']);
    $routes->get('/apee/years/{id}/entries', [ApeeController::class, 'entries']);
    $routes->get('/apee/years/{id}/statement', [ApeeController::class, 'statement']);
    $routes->post('/apee/years/{id}/statement/document', [ApeeController::class, 'issueStatement']);
    $routes->get('/apee/years/{id}/non-contributors', [ApeeController::class, 'nonContributors']);
    $routes->get('/apee/years/{id}/exemptions', [ApeeController::class, 'exemptions']);
    $routes->post('/apee/years/{id}/exemptions', [ApeeController::class, 'exempt']);

    // Paquet minimum et dons en nature (EX-APE-05), bordereau DOC-27.
    $routes->get('/supplies', [ApeeController::class, 'supplies']);
    $routes->post('/supplies', [ApeeController::class, 'receiveSupply']);
    $routes->get('/supplies/{id}', [ApeeController::class, 'supply']);
    $routes->post('/supplies/{id}/slip', [ApeeController::class, 'supplySlip']);
};
