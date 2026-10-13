<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Higher\Enrolment\Http\EnrolmentController;
use Scholaris\Modules\Apps\Higher\Enrolment\Http\StudentController;
use Scholaris\Modules\Apps\Higher\Support\HigherRoutes;

/**
 * Inscriptions (INS) : inscriptions administratives et pédagogiques, réinscriptions, statuts, contrat pédagogique, carte et fiche,
 * dossier étudiant, consentement, dérogations, décisions de passage. Routes enregistrées par HigherRoutes (WriteGate).
 */
return static function (HigherRoutes $routes): void {
    $routes->get('/enrolments', [EnrolmentController::class, 'index']);
    $routes->post('/enrolments', [EnrolmentController::class, 'create']);
    $routes->get('/enrolments/{id}', [EnrolmentController::class, 'show']);
    $routes->post('/enrolments/{id}/validate', [EnrolmentController::class, 'validate']);
    $routes->post('/enrolments/{id}/status', [EnrolmentController::class, 'status']);
    $routes->post('/enrolments/{id}/change', [EnrolmentController::class, 'change']);
    $routes->post('/enrolments/{id}/link', [EnrolmentController::class, 'link']);
    $routes->get('/enrolments/{id}/learning-contract', [EnrolmentController::class, 'contract']);
    $routes->put('/enrolments/{id}/learning-contract', [EnrolmentController::class, 'chooseUnits']);
    $routes->post('/enrolments/{id}/student-card', [EnrolmentController::class, 'studentCard']);
    $routes->post('/enrolments/{id}/enrolment-form', [EnrolmentController::class, 'enrolmentForm']);
    $routes->get('/enrolments/{id}/events', [EnrolmentController::class, 'events']);

    $routes->get('/students/{personId}', [StudentController::class, 'show']);
    $routes->patch('/students/{personId}', [StudentController::class, 'update']);
    $routes->put('/students/{personId}/guarantor-consent', [StudentController::class, 'consent']);
    $routes->post('/enrolment-derogations', [StudentController::class, 'derogate']);
    $routes->get('/progression-decisions', [StudentController::class, 'decisions']);
    $routes->post('/progression-decisions', [StudentController::class, 'recordDecision']);
};
