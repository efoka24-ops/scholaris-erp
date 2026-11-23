<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Higher\Internships\Http\CompanyController;
use Scholaris\Modules\Apps\Higher\Internships\Http\DefenseController;
use Scholaris\Modules\Apps\Higher\Internships\Http\InternshipController;
use Scholaris\Modules\Apps\Higher\Internships\Http\StudentInternshipController;
use Scholaris\Modules\Apps\Higher\Support\HigherRoutes;

/**
 * Stages et soutenances (STG) : entreprises d'accueil, conventions (DOC-20) et signatures, suivi et fiche d'évaluation (DOC-23),
 * attestation de fin de stage, mémoire, soutenances (API-18) avec jury, convocation (DOC-21, NOT-13), note et PV (DOC-22),
 * « Mon stage » de l'étudiant. Toutes les routes passent par HigherRoutes (préfixe /higher ; WriteGate avant chaque écriture).
 */
return static function (HigherRoutes $routes): void {
    $routes->get('/internship-companies', [CompanyController::class, 'index']);
    $routes->post('/internship-companies', [CompanyController::class, 'create']);
    $routes->patch('/internship-companies/{id}', [CompanyController::class, 'update']);

    $routes->get('/internships', [InternshipController::class, 'index']);
    $routes->post('/internships', [InternshipController::class, 'create']);
    $routes->get('/internships/{id}', [InternshipController::class, 'show']);
    $routes->patch('/internships/{id}', [InternshipController::class, 'update']);
    $routes->post('/internships/{id}/convention', [InternshipController::class, 'convention']);
    $routes->post('/internships/{id}/signatures', [InternshipController::class, 'sign']);
    $routes->post('/internships/{id}/subject/validate', [InternshipController::class, 'validateSubject']);
    $routes->post('/internships/{id}/completion', [InternshipController::class, 'complete']);
    $routes->post('/internships/{id}/thesis', [InternshipController::class, 'thesis']);
    $routes->post('/internships/{id}/cancel', [InternshipController::class, 'cancel']);
    $routes->get('/internships/{id}/readiness', [InternshipController::class, 'readiness']);
    $routes->get('/internships/{id}/followups', [InternshipController::class, 'followups']);
    $routes->post('/internships/{id}/followups', [InternshipController::class, 'addFollowup']);

    $routes->get('/defenses', [DefenseController::class, 'index']);
    $routes->post('/defenses', [DefenseController::class, 'create']);
    $routes->get('/defenses/{id}', [DefenseController::class, 'show']);
    $routes->patch('/defenses/{id}', [DefenseController::class, 'update']);
    $routes->post('/defenses/{id}/convene', [DefenseController::class, 'convene']);
    $routes->post('/defenses/{id}/grade', [DefenseController::class, 'grade']);
    $routes->post('/defenses/{id}/minutes', [DefenseController::class, 'minutes']);
    $routes->post('/defenses/{id}/cancel', [DefenseController::class, 'cancel']);

    $routes->get('/students/me/internship', [StudentInternshipController::class, 'show']);
    $routes->post('/students/me/internship/thesis', [StudentInternshipController::class, 'thesis']);
};
