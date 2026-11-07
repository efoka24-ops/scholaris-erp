<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Secondary\Grades\Http\GradesController;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryRoutes;

/**
 * Notes (noyau, T868 à T870) : paramètres, grille, contrôle d'affectation (TST-09), relevé DOC-08, fraude, épreuves communes,
 * notifications. Épreuves, notes, import, verrouillage et corrections : routes du socle Assessment (D4).
 */
return static function (SecondaryRoutes $routes): void {
    $routes->get('/grades/settings', [GradesController::class, 'settings']);
    $routes->post('/grades/settings/preset', [GradesController::class, 'preset']);
    $routes->get('/grades/sheet', [GradesController::class, 'sheet']);
    $routes->post('/grades/entry-checks', [GradesController::class, 'entryCheck']);
    $routes->get('/grades/statement', [GradesController::class, 'statement']);
    $routes->get('/grades/fraud-reports', [GradesController::class, 'fraudReports']);
    $routes->post('/grades/fraud-reports', [GradesController::class, 'reportFraud']);
    $routes->post('/grades/fraud-reports/{id}/handle', [GradesController::class, 'handleFraud']);
    $routes->get('/grades/common-tests', [GradesController::class, 'commonTests']);
    $routes->post('/grades/common-tests', [GradesController::class, 'createCommonTest']);
    $routes->patch('/grades/common-tests/{id}', [GradesController::class, 'updateCommonTest']);
    $routes->post('/grades/publications', [GradesController::class, 'published']);
    $routes->post('/grades/late-reminders', [GradesController::class, 'lateReminders']);
};
