<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Secondary\Portals\Http\PortalController;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryRoutes;

/**
 * Portails élève et parent du secondaire dans l'établissement actif (noyau). La section « secondary » des portails du socle
 * (/portal/learner, /portal/family) est fournie par la clé `portals.sections.secondary`, sans route propre.
 */
return static function (SecondaryRoutes $routes): void {
    $routes->get('/portal/student', [PortalController::class, 'student']);
    $routes->get('/portal/student/school-life', [PortalController::class, 'studentLife']);
    $routes->get('/portal/family', [PortalController::class, 'family']);
    $routes->get('/portal/family/children/{id}/school-life', [PortalController::class, 'childLife']);
    $routes->get('/portal/teacher', [PortalController::class, 'teacher']);
    $routes->post('/portal/family/children/{id}/payments', [PortalController::class, 'pay']);
    $routes->get('/portal/family/children/{id}/payments/{paymentId}', [PortalController::class, 'paymentStatus']);
};
