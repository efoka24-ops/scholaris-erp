<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\PublicNursery\Development\Http\FrameController;
use Scholaris\Modules\Apps\PublicNursery\Development\Http\LifeBookController;
use Scholaris\Modules\Apps\PublicNursery\Development\Http\NeedReportController;
use Scholaris\Modules\Apps\PublicNursery\Development\Http\ObservationController;
use Scholaris\Modules\Apps\PublicNursery\Support\NurseryRoutes;

return static function (NurseryRoutes $routes): void {
    $routes->get('/development/frames', [FrameController::class, 'index']);
    $routes->get('/development/frames/{id}', [FrameController::class, 'show']);
    $routes->post('/development/frames', [FrameController::class, 'store']);
    $routes->post('/development/frames/import', [FrameController::class, 'importCsv']);
    $routes->get('/development/lifebook', [LifeBookController::class, 'index']);
    $routes->get('/development/lifebook/{id}', [LifeBookController::class, 'show']);
    $routes->post('/development/lifebook', [LifeBookController::class, 'store']);
    $routes->post('/development/lifebook/{id}/media', [LifeBookController::class, 'attachMedia']);
    $routes->post('/development/lifebook/{id}/publish', [LifeBookController::class, 'publish']);
    $routes->get('/development/lifebook/{id}/media/{mediaId}', [LifeBookController::class, 'media']);
    $routes->post('/sync/observations', [ObservationController::class, 'store']);
    $routes->get('/development/needs', [NeedReportController::class, 'index']);
    $routes->post('/development/needs', [NeedReportController::class, 'store']);
};
