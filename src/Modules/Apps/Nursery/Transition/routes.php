<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Nursery\Support\NurseryRoutes;
use Scholaris\Modules\Apps\Nursery\Transition\Http\TransitionController;

return static function (NurseryRoutes $routes): void {
    $routes->get('/transition/decisions', [TransitionController::class, 'index']);
    $routes->post('/transition/proposals', [TransitionController::class, 'propose']);
    $routes->post('/transition/decisions/{id}/opinion', [TransitionController::class, 'opinion']);
    $routes->post('/transition/decisions/{id}/decide', [TransitionController::class, 'decide']);
    $routes->get('/transition/closure', [TransitionController::class, 'closure']);
    $routes->get('/transition/liaison-sheets', [TransitionController::class, 'liaisons']);
    $routes->post('/transition/liaison-sheets', [TransitionController::class, 'createLiaison']);
    $routes->patch('/transition/liaison-sheets/{id}', [TransitionController::class, 'updateLiaison']);
    $routes->post('/transition/liaison-sheets/{id}/sign', [TransitionController::class, 'signLiaison']);
};
