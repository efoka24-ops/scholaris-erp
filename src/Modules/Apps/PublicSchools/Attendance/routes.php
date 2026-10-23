<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\PublicSchools\Attendance\Http\AlertController;
use Scholaris\Modules\Apps\PublicSchools\Attendance\Http\AttendanceController;
use Scholaris\Modules\Apps\PublicSchools\Attendance\Http\TeacherPresenceController;
use Scholaris\Modules\Apps\PublicSchools\Support\PublicRoutes;

return static function (PublicRoutes $routes): void {
    // PRE : appel des élèves par demi-journée (API-05), état du jour, données du cahier d'appel (DOC-06).
    $routes->put('/classes/{id}/attendance/{date}', [AttendanceController::class, 'record']);
    $routes->get('/classes/{id}/attendance/{date}', [AttendanceController::class, 'show']);
    $routes->get('/classes/{id}/attendance-register', [AttendanceController::class, 'register']);

    // PRE : présence des enseignants, contrôle de l'inspection, synthèse mensuelle (DOC-22), classes sans enseignant (RG-PRE-2).
    $routes->put('/staff/{id}/presence/{date}', [TeacherPresenceController::class, 'declare']);
    $routes->put('/staff/{id}/presence/{date}/check', [TeacherPresenceController::class, 'check']);
    $routes->get('/teacher-presence', [TeacherPresenceController::class, 'index']);
    $routes->get('/teacher-presence/control-sample', [TeacherPresenceController::class, 'sample']);
    $routes->get('/teacher-presence/summary', [TeacherPresenceController::class, 'summary']);
    $routes->get('/uncovered-classes', [TeacherPresenceController::class, 'uncovered']);

    // Alertes d'assiduité (NOT-03, NOT-12, NOT-13).
    $routes->get('/attendance-alerts', [AlertController::class, 'index']);
};
