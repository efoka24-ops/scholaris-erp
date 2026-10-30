<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\PublicNursery\Support\NurseryRoutes;
use Scholaris\Modules\Apps\PublicNursery\Transition\Http\LiaisonController;
use Scholaris\Modules\Apps\PublicNursery\Transition\Http\TransitionController;

return static function (NurseryRoutes $routes): void {
    $routes->get('/transition/decisions', [TransitionController::class, 'index']);
    $routes->post('/transition/decisions/propose', [TransitionController::class, 'propose']);
    $routes->post('/transition/decisions/{id}/decide', [TransitionController::class, 'decide']);
    $routes->get('/transition/liaison', [LiaisonController::class, 'show']);
    $routes->post('/transition/liaison', [LiaisonController::class, 'store']);
    $routes->post('/transition/liaison/sign', [LiaisonController::class, 'sign']);
};
