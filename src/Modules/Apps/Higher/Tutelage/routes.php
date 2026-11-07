<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Higher\Support\HigherRoutes;
use Scholaris\Modules\Apps\Higher\Tutelage\Http\TutelageController;

/**
 * Tutelle (TUT) : convention de l'année et échéance, transmissions (DOC-18, notes, relevés, PV), dossiers de diplôme (RG-TUT-2),
 * accès lecture de la tutelle (R22, journalisé, désactivé par défaut). Toutes les routes passent par HigherRoutes.
 */
return static function (HigherRoutes $routes): void {
    $routes->get('/tutelage/convention', [TutelageController::class, 'convention']);
    $routes->get('/tutelage/transmissions', [TutelageController::class, 'transmissions']);
    $routes->post('/tutelage/transmissions', [TutelageController::class, 'transmit']);
    $routes->post('/tutelage/transmissions/{id}/answer', [TutelageController::class, 'answer']);
    $routes->get('/tutelage/diploma-files', [TutelageController::class, 'diplomaFiles']);
    $routes->post('/tutelage/diploma-files', [TutelageController::class, 'createDiplomaFile']);
    $routes->patch('/tutelage/diploma-files/{id}', [TutelageController::class, 'moveDiplomaFile']);
    $routes->get('/tutelage/portal/enrolments', [TutelageController::class, 'portalEnrolments']);
    $routes->get('/tutelage/consultations', [TutelageController::class, 'consultations']);
};
