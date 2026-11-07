<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Higher\Academic\Http\ProgramController;
use Scholaris\Modules\Apps\Higher\Academic\Http\RegisterController;
use Scholaris\Modules\Apps\Higher\Academic\Http\RegulationController;
use Scholaris\Modules\Apps\Higher\Academic\Http\SchoolController;
use Scholaris\Modules\Apps\Higher\Support\HigherRoutes;

/**
 * Paramétrage académique (ACA) : écoles, départements, filières, niveaux, groupes, autorisations MINESUP, tutelles, règlement des
 * études, calendrier. Toutes les routes passent par HigherRoutes (préfixe /higher ; WriteGate avant chaque écriture).
 */
return static function (HigherRoutes $routes): void {
    $routes->get('/schools', [SchoolController::class, 'index']);
    $routes->post('/schools', [SchoolController::class, 'create']);
    $routes->get('/schools/{id}', [SchoolController::class, 'show']);
    $routes->patch('/schools/{id}', [SchoolController::class, 'update']);
    $routes->get('/schools/{id}/settings', [SchoolController::class, 'settings']);
    $routes->get('/departments', [SchoolController::class, 'departments']);
    $routes->post('/departments', [SchoolController::class, 'createDepartment']);
    $routes->patch('/departments/{id}', [SchoolController::class, 'updateDepartment']);

    $routes->get('/programs', [ProgramController::class, 'index']);
    $routes->post('/programs', [ProgramController::class, 'create']);
    $routes->get('/programs/{id}', [ProgramController::class, 'show']);
    $routes->patch('/programs/{id}', [ProgramController::class, 'update']);
    $routes->get('/levels', [ProgramController::class, 'levels']);
    $routes->post('/levels', [ProgramController::class, 'createLevel']);
    $routes->patch('/levels/{id}', [ProgramController::class, 'updateLevel']);
    $routes->get('/groups', [ProgramController::class, 'groups']);
    $routes->post('/groups', [ProgramController::class, 'createGroup']);
    $routes->patch('/groups/{id}', [ProgramController::class, 'updateGroup']);

    $routes->get('/authorizations', [RegisterController::class, 'authorizations']);
    $routes->post('/authorizations', [RegisterController::class, 'createAuthorization']);
    $routes->get('/authorizations/expiring', [RegisterController::class, 'expiring']);
    $routes->get('/authorizations/{id}', [RegisterController::class, 'showAuthorization']);
    $routes->patch('/authorizations/{id}', [RegisterController::class, 'updateAuthorization']);
    $routes->delete('/authorizations/{id}', [RegisterController::class, 'deleteAuthorization']);
    $routes->get('/tutelages', [RegisterController::class, 'tutelages']);
    $routes->post('/tutelages', [RegisterController::class, 'createTutelage']);
    $routes->get('/tutelages/{id}', [RegisterController::class, 'showTutelage']);
    $routes->patch('/tutelages/{id}', [RegisterController::class, 'updateTutelage']);
    $routes->get('/eligibility', [RegisterController::class, 'eligibility']);

    $routes->get('/regulations', [RegulationController::class, 'index']);
    $routes->post('/regulations', [RegulationController::class, 'create']);
    $routes->get('/regulations/{id}', [RegulationController::class, 'show']);
    $routes->patch('/regulations/{id}', [RegulationController::class, 'update']);
    $routes->post('/regulations/{id}/freeze', [RegulationController::class, 'freeze']);
    $routes->post('/regulations/{id}/amend', [RegulationController::class, 'amend']);
    $routes->get('/regulations/{id}/revisions', [RegulationController::class, 'revisions']);
    $routes->get('/calendar-entries', [RegulationController::class, 'calendarEntries']);
    $routes->post('/calendar-entries', [RegulationController::class, 'createCalendarEntry']);
    $routes->patch('/calendar-entries/{id}', [RegulationController::class, 'updateCalendarEntry']);
    $routes->delete('/calendar-entries/{id}', [RegulationController::class, 'deleteCalendarEntry']);
};
