<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Higher\Support\HigherRoutes;
use Scholaris\Modules\Apps\Higher\Teaching\Http\ExamController;
use Scholaris\Modules\Apps\Higher\Teaching\Http\SessionLogController;
use Scholaris\Modules\Apps\Higher\Teaching\Http\TeachingController;
use Scholaris\Modules\Apps\Higher\Teaching\Http\TimetableController;

/**
 * Enseignement (EDT, ENS hors paiement) : dossiers enseignants, salles, affectations, emploi du temps, reports, consommation des
 * volumes, cahier de textes et émargement, validation des heures, planning des examens. Routes enregistrées par HigherRoutes (WriteGate).
 */
return static function (HigherRoutes $routes): void {
    $routes->get('/teaching/teachers', [TeachingController::class, 'teachers']);
    $routes->post('/teaching/teachers', [TeachingController::class, 'saveTeacher']);
    $routes->get('/teaching/teachers/{personId}', [TeachingController::class, 'teacher']);
    $routes->patch('/teaching/teachers/{personId}', [TeachingController::class, 'saveTeacher']);
    $routes->get('/teaching/rooms', [TeachingController::class, 'rooms']);
    $routes->post('/teaching/rooms', [TeachingController::class, 'saveRoom']);
    $routes->patch('/teaching/rooms/{id}', [TeachingController::class, 'saveRoom']);

    $routes->get('/teaching/assignments', [TeachingController::class, 'assignmentsIndex']);
    $routes->post('/teaching/assignments', [TeachingController::class, 'assign']);
    $routes->get('/teaching/assignments/{id}', [TeachingController::class, 'assignment']);
    $routes->patch('/teaching/assignments/{id}', [TeachingController::class, 'changeAssignment']);
    $routes->post('/teaching/assignments/{id}/end', [TeachingController::class, 'endAssignment']);
    $routes->post('/teaching/assignments/{id}/overrun-approvals', [TeachingController::class, 'approveOverrun']);

    $routes->get('/teaching/group-delegates', [TeachingController::class, 'delegates']);
    $routes->post('/teaching/group-delegates', [TeachingController::class, 'designateDelegate']);
    $routes->delete('/teaching/group-delegates/{id}', [TeachingController::class, 'removeDelegate']);

    $routes->get('/timetable', [TimetableController::class, 'view']);
    $routes->post('/timetable/check', [TimetableController::class, 'check']);
    $routes->post('/timetable/entries', [TimetableController::class, 'create']);
    $routes->get('/timetable/entries/{id}', [TimetableController::class, 'show']);
    $routes->delete('/timetable/entries/{id}', [TimetableController::class, 'delete']);
    $routes->get('/timetable/conflicts', [TimetableController::class, 'conflicts']);
    $routes->post('/timetable/changes', [TimetableController::class, 'change']);
    $routes->get('/timetable/consumption', [TimetableController::class, 'consumption']);
    $routes->post('/timetable/document', [TimetableController::class, 'document']);

    $routes->get('/session-logs', [SessionLogController::class, 'index']);
    $routes->post('/session-logs', [SessionLogController::class, 'create']);
    $routes->get('/session-logs/{id}', [SessionLogController::class, 'show']);
    $routes->patch('/session-logs/{id}', [SessionLogController::class, 'update']);
    $routes->post('/session-logs/{id}/sign', [SessionLogController::class, 'sign']);
    $routes->post('/session-logs/{id}/countersign', [SessionLogController::class, 'countersign']);
    $routes->get('/teaching/hours', [SessionLogController::class, 'hours']);
    $routes->get('/teaching/hours-validations', [SessionLogController::class, 'validations']);
    $routes->post('/teaching/hours-validations', [SessionLogController::class, 'validateMonth']);

    $routes->get('/exam-schedules', [ExamController::class, 'index']);
    $routes->post('/exam-schedules', [ExamController::class, 'create']);
    $routes->get('/exam-schedules/{id}', [ExamController::class, 'show']);
    $routes->post('/exam-schedules/{id}/publish', [ExamController::class, 'publish']);
    $routes->post('/exam-schedules/{id}/cancel', [ExamController::class, 'cancel']);
    $routes->post('/exam-schedules/{id}/convocations', [ExamController::class, 'convocations']);
    $routes->post('/exam-schedules/{id}/attendance-sheet', [ExamController::class, 'attendanceSheet']);
};
