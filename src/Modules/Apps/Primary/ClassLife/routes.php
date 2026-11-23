<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Primary\ClassLife\Http\JournalController;
use Scholaris\Modules\Apps\Primary\Support\PrimaryRoutes;

/** Cahier de liaison de la classe (maître) et portail parent. */
return static function (PrimaryRoutes $routes): void {
    $routes->get('/classes/{id}/journal', [JournalController::class, 'index']);
    $routes->post('/classes/{id}/journal', [JournalController::class, 'create']);
    $routes->patch('/journal/{id}', [JournalController::class, 'update']);
    $routes->post('/journal/{id}/publish', [JournalController::class, 'publish']);
    $routes->post('/journal/{id}/seen', [JournalController::class, 'seen']);

    $routes->get('/family/children', [JournalController::class, 'children']);
    $routes->get('/family/children/{id}/journal', [JournalController::class, 'familyJournal']);
    $routes->get('/family/children/{id}/competencies', [JournalController::class, 'familyCompetencies']);
};
