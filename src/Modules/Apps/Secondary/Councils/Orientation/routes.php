<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Secondary\Councils\Orientation\Http\OrientationController;
use Scholaris\Modules\Apps\Secondary\Councils\Orientation\Http\OrientationSheetController;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryRoutes;

/** Toutes les routes de ce sous-module passent par la garde explicite `orientation`. */
return static function (SecondaryRoutes $routes): void {
    $orientation = $routes->module('orientation');
    $orientation->get('/orientation', [OrientationController::class, 'index']);
    $orientation->get('/orientation/me', [OrientationController::class, 'showOwn']);
    $orientation->put('/orientation/me/wishes', [OrientationController::class, 'saveOwnWishes']);
    $orientation->get('/orientation/family', [OrientationController::class, 'familyCases']);
    $orientation->get('/orientation/students/{personId}', [OrientationController::class, 'show']);
    $orientation->put('/orientation/students/{personId}/wishes', [OrientationController::class, 'saveWishes']);
    $orientation->put('/orientation/students/{personId}/advice', [OrientationController::class, 'saveAdvice']);
    $orientation->post('/orientation/students/{personId}/decisions', [OrientationController::class, 'decide']);
    $orientation->get('/orientation/appeals', [OrientationController::class, 'appeals']);
    $orientation->get('/orientation/me/appeals', [OrientationController::class, 'ownAppeals']);
    $orientation->post('/orientation/students/{personId}/appeals', [OrientationController::class, 'appeal']);
    $orientation->post('/orientation/appeals/{id}/decide', [OrientationController::class, 'decideAppeal']);
    $orientation->post('/orientation/students/{personId}/sheet', [OrientationSheetController::class, 'issue']);
};
