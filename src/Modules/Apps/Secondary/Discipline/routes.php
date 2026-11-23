<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Secondary\Discipline\Http\DisciplineController;
use Scholaris\Modules\Apps\Secondary\Discipline\Http\DisciplineCouncilController;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryRoutes;

/** Discipline et appel (noyau, lot E2-B : T864 à T867) ; l'appel par heure lui-même reste aux routes du socle /attendance-sessions (D4). */
return static function (SecondaryRoutes $routes): void {
    $routes->get('/discipline/scale', [DisciplineController::class, 'scale']);
    $routes->get('/discipline/hours', [DisciplineController::class, 'hours']);
    $routes->post('/discipline/proposals/compute', [DisciplineController::class, 'compute']);
    $routes->get('/discipline/proposals', [DisciplineController::class, 'proposals']);
    $routes->get('/discipline/proposals/{id}', [DisciplineController::class, 'proposal']);
    $routes->post('/discipline/proposals/{id}/decide', [DisciplineController::class, 'decide']);
    $routes->get('/discipline/class-exclusions', [DisciplineController::class, 'exclusions']);
    $routes->post('/discipline/class-exclusions', [DisciplineController::class, 'reportExclusion']);
    $routes->get('/discipline/councils', [DisciplineCouncilController::class, 'index']);
    $routes->post('/discipline/councils', [DisciplineCouncilController::class, 'convene']);
    $routes->get('/discipline/councils/{id}', [DisciplineCouncilController::class, 'show']);
    $routes->post('/discipline/councils/{id}/decide', [DisciplineCouncilController::class, 'decide']);
};
