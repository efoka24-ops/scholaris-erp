<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Secondary\Support\SecondaryRoutes;
use Scholaris\Modules\Apps\Secondary\Timetable\Http\TimetableController;

/**
 * Emploi du temps (noyau, préfixe /secondary ; T860, T861) : affectations, plages horaires, disponibilités, salle de classe, vue,
 * pose manuelle et contrôle des conflits, génération assistée, remplacements et permutations notifiés (NOT-14), DOC-07.
 * Un atelier exige le module `workshops` : contrôlé par l'affectation (409 `module_not_enabled`), pas par la route.
 */
return static function (SecondaryRoutes $routes): void {
    $routes->get('/timetable', [TimetableController::class, 'view']);
    $routes->get('/timetable/assignments', [TimetableController::class, 'assignments']);
    $routes->get('/timetable/teachers', [TimetableController::class, 'teachers']);
    $routes->post('/timetable/assignments', [TimetableController::class, 'createAssignment']);
    $routes->patch('/timetable/assignments/{id}', [TimetableController::class, 'updateAssignment']);
    $routes->get('/timetable/bands', [TimetableController::class, 'bands']);
    $routes->put('/timetable/bands', [TimetableController::class, 'setBands']);
    $routes->get('/timetable/availabilities', [TimetableController::class, 'availabilities']);
    $routes->put('/timetable/availabilities', [TimetableController::class, 'setAvailabilities']);
    $routes->put('/timetable/class-rooms', [TimetableController::class, 'setClassRoom']);
    $routes->post('/timetable/entries', [TimetableController::class, 'createEntry']);
    $routes->post('/timetable/entries/check', [TimetableController::class, 'checkEntry']);
    $routes->delete('/timetable/entries/{id}', [TimetableController::class, 'deleteEntry']);
    $routes->get('/timetable/conflicts', [TimetableController::class, 'conflicts']);
    $routes->post('/timetable/generate', [TimetableController::class, 'generate']);
    $routes->get('/timetable/runs', [TimetableController::class, 'runs']);
    $routes->get('/timetable/changes', [TimetableController::class, 'changes']);
    $routes->post('/timetable/changes', [TimetableController::class, 'change']);
    $routes->get('/timetable/document', [TimetableController::class, 'document']);
};
