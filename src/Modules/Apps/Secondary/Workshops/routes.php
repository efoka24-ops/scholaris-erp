<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Secondary\Support\SecondaryRoutes;
use Scholaris\Modules\Apps\Secondary\Workshops\Http\WorkshopsController;

/** Chaque route porte exactement sa garde workshops ou internships (Modules::OWNED_BY). */
return static function (SecondaryRoutes $routes): void {
    $workshops = $routes->module('workshops');
    $workshops->get('/workshops', [WorkshopsController::class, 'workshops']);
    $workshops->post('/workshops', [WorkshopsController::class, 'createWorkshop']);
    $workshops->patch('/workshops/{id}', [WorkshopsController::class, 'updateWorkshop']);
    $workshops->get('/workshops/workstations', [WorkshopsController::class, 'workstations']);
    $workshops->post('/workshops/workstations', [WorkshopsController::class, 'createWorkstation']);
    $workshops->patch('/workshops/workstations/{id}', [WorkshopsController::class, 'updateWorkstation']);
    $workshops->get('/workshops/sheets', [WorkshopsController::class, 'sheets']);
    $workshops->post('/workshops/sheets', [WorkshopsController::class, 'createSheet']);
    $workshops->patch('/workshops/sheets/{id}', [WorkshopsController::class, 'updateSheet']);
    $workshops->get('/workshops/sessions', [WorkshopsController::class, 'sessions']);
    $workshops->post('/workshops/{workshop_id}/sessions', [WorkshopsController::class, 'createSession']);
    $workshops->get('/workshops/materials', [WorkshopsController::class, 'items']);
    $workshops->post('/workshops/materials', [WorkshopsController::class, 'createItem']);
    $workshops->post('/workshops/materials/{id}/moves', [WorkshopsController::class, 'moveItem']);
    $workshops->get('/workshops/safety-events', [WorkshopsController::class, 'safetyEvents']);
    $workshops->post('/workshops/safety-events', [WorkshopsController::class, 'createSafetyEvent']);
    $workshops->post('/workshops/sessions/{session_id}/evaluations', [WorkshopsController::class, 'evaluate']);

    $internships = $routes->module('internships');
    $internships->get('/internships/companies', [WorkshopsController::class, 'companies']);
    $internships->post('/internships/companies', [WorkshopsController::class, 'createCompany']);
    $internships->patch('/internships/companies/{id}', [WorkshopsController::class, 'updateCompany']);
    $internships->get('/internships', [WorkshopsController::class, 'internships']);
    $internships->post('/internships', [WorkshopsController::class, 'createInternship']);
    $internships->patch('/internships/{id}', [WorkshopsController::class, 'updateInternship']);
    $internships->get('/internships/visits', [WorkshopsController::class, 'visits']);
    $internships->post('/internships/{internship_id}/visits', [WorkshopsController::class, 'createVisit']);
};
