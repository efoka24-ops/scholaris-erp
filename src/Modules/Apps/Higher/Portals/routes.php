<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Higher\Portals\Student\StudentPortalController;
use Scholaris\Modules\Apps\Higher\Support\HigherRoutes;

/**
 * Routes des portails de l'IPES (préfixe /higher, WriteGate avant chaque écriture). Portail étudiant et garant : Portals/Student
 * (vague 1) ; portails jury et tutelle : vague 3, à ajouter dans ce fichier.
 */
return static function (HigherRoutes $routes): void {
    $routes->get('/portal/student', [StudentPortalController::class, 'student']);
    $routes->post('/portal/student/payments', [StudentPortalController::class, 'pay']);
    $routes->get('/portal/guarantor', [StudentPortalController::class, 'guarantor']);
};
