<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Higher\Attendance\Http\AssiduityController;
use Scholaris\Modules\Apps\Higher\Attendance\Http\DisciplineController;
use Scholaris\Modules\Apps\Higher\Support\HigherRoutes;

/**
 * Présences et discipline (PRE) : assiduité par EC, alertes NOT-07, non-autorisés à composer, présence des examens nationaux,
 * épreuves de remplacement, sanctions et conseil de discipline. L'appel (par séance ou QR, hors ligne) et les justificatifs restent
 * aux routes du socle. Routes enregistrées par HigherRoutes (WriteGate).
 */
return static function (HigherRoutes $routes): void {
    $routes->get('/attendance/assiduity', [AssiduityController::class, 'element']);
    $routes->get('/attendance/barred', [AssiduityController::class, 'barred']);
    $routes->get('/attendance/students/{personId}/assiduity', [AssiduityController::class, 'student']);
    $routes->get('/attendance/students/{personId}/presence', [AssiduityController::class, 'presence']);
    $routes->post('/attendance/alerts', [AssiduityController::class, 'alerts']);
    $routes->get('/attendance/barred-lists', [AssiduityController::class, 'lists']);
    $routes->post('/attendance/barred-lists', [AssiduityController::class, 'transmit']);
    $routes->post('/attendance/barred-lists/{id}/receive', [AssiduityController::class, 'receive']);
    $routes->post('/attendance/barred-lifts', [AssiduityController::class, 'lift']);
    $routes->get('/attendance/replacement-exams', [AssiduityController::class, 'replacements']);
    $routes->post('/attendance/replacement-exams', [AssiduityController::class, 'requestReplacement']);
    $routes->post('/attendance/replacement-exams/{id}/schedule', [AssiduityController::class, 'scheduleReplacement']);
    $routes->post('/attendance/replacement-exams/{id}/close', [AssiduityController::class, 'closeReplacement']);

    $routes->get('/discipline/sanctions', [DisciplineController::class, 'sanctions']);
    $routes->post('/discipline/sanctions', [DisciplineController::class, 'sanction']);
    $routes->post('/discipline/sanctions/{id}/notification', [DisciplineController::class, 'notify']);
    $routes->get('/discipline/councils', [DisciplineController::class, 'councils']);
    $routes->post('/discipline/councils', [DisciplineController::class, 'convene']);
    $routes->get('/discipline/councils/{id}', [DisciplineController::class, 'council']);
    $routes->post('/discipline/councils/{id}/hearing', [DisciplineController::class, 'hearing']);
    $routes->post('/discipline/councils/{id}/sign', [DisciplineController::class, 'sign']);
};
