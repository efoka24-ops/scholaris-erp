<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Secondary\Support\SecondaryRoutes;
use Scholaris\Modules\Apps\Secondary\Syllabus\Http\SyllabusController;

/**
 * Cahier de textes (noyau, préfixe /secondary ; T862, T863) : API-06 `POST /secondary/lesson-logs` (séance signée, hors ligne par
 * client_uuid), séances, progressions, couverture, cahier de la classe DOC-25, rapport DOC-26, visas, rappels NOT-12.
 */
return static function (SecondaryRoutes $routes): void {
    $routes->post('/lesson-logs', [SyllabusController::class, 'createLog']);
    $routes->get('/syllabus/lesson-logs', [SyllabusController::class, 'logs']);
    $routes->get('/syllabus/progressions', [SyllabusController::class, 'progressionList']);
    $routes->post('/syllabus/progressions', [SyllabusController::class, 'createProgression']);
    $routes->get('/syllabus/progressions/{id}', [SyllabusController::class, 'showProgression']);
    $routes->put('/syllabus/progressions/{id}/lessons', [SyllabusController::class, 'replaceLessons']);
    $routes->post('/syllabus/progressions/{id}/approve', [SyllabusController::class, 'approveProgression']);
    $routes->get('/syllabus/coverage', [SyllabusController::class, 'classCoverage']);
    $routes->get('/syllabus/coverage/report', [SyllabusController::class, 'report']);
    $routes->get('/syllabus/logbook', [SyllabusController::class, 'logbook']);
    $routes->get('/syllabus/visas', [SyllabusController::class, 'visas']);
    $routes->post('/syllabus/visas', [SyllabusController::class, 'addVisa']);
    $routes->post('/syllabus/reminders', [SyllabusController::class, 'remind']);
};
