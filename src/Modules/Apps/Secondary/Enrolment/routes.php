<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Secondary\Enrolment\Http\EnrolmentController;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryRoutes;

/**
 * Inscriptions (noyau, T824 à T827) : API-01 inscription, API-02 matricule national (identifiant interne dans l'adresse, jamais le
 * matricule), dossiers, préinscriptions et fichier du primaire, réinscription, transferts, import des élèves.
 */
return static function (SecondaryRoutes $routes): void {
    $routes->post('/enrolments', [EnrolmentController::class, 'enrol']);
    $routes->get('/enrolment/students', [EnrolmentController::class, 'students']);
    $routes->get('/enrolment/directory', [EnrolmentController::class, 'directory']);
    $routes->get('/enrolment/students/{id}', [EnrolmentController::class, 'student']);
    $routes->put('/students/{id}/national-id', [EnrolmentController::class, 'nationalId']);
    $routes->post('/enrolment/students/{id}/guarantors', [EnrolmentController::class, 'guarantor']);

    $routes->get('/enrolment/preregistrations', [EnrolmentController::class, 'preregistrations']);
    $routes->post('/enrolment/preregistrations', [EnrolmentController::class, 'createPreregistration']);
    $routes->patch('/enrolment/preregistrations/{id}', [EnrolmentController::class, 'updatePreregistration']);
    $routes->post('/enrolment/preregistrations/{id}/convert', [EnrolmentController::class, 'convertPreregistration']);
    $routes->post('/enrolment/preregistrations/{id}/slip', [EnrolmentController::class, 'preregistrationSlip']);
    $routes->post('/enrolment/primary-files', [EnrolmentController::class, 'primaryFile']);

    $routes->get('/enrolment/reenrolments/candidates', [EnrolmentController::class, 'reenrolmentCandidates']);
    $routes->post('/enrolment/reenrolments', [EnrolmentController::class, 'reenrol']);

    $routes->get('/enrolment/transfers', [EnrolmentController::class, 'transfers']);
    $routes->post('/enrolment/transfers/out', [EnrolmentController::class, 'transferOut']);
    $routes->post('/enrolment/transfers/in', [EnrolmentController::class, 'transferIn']);

    $routes->get('/enrolment/imports/template', [EnrolmentController::class, 'importTemplate']);
    $routes->post('/enrolment/imports', [EnrolmentController::class, 'import']);
};
