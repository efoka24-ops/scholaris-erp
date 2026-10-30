<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Nursery\Development\Http\FrameController;
use Scholaris\Modules\Apps\Nursery\Development\Http\LifeBookController;
use Scholaris\Modules\Apps\Nursery\Development\Http\NeedController;
use Scholaris\Modules\Apps\Nursery\Development\Http\ObservationController;
use Scholaris\Modules\Apps\Nursery\Development\Http\PhotoConsentController;
use Scholaris\Modules\Apps\Nursery\Development\Http\ProgressBookController;
use Scholaris\Modules\Apps\Nursery\Support\NurseryRoutes;

return static function (NurseryRoutes $routes): void {
    $routes->get('/frames', [FrameController::class, 'index']);
    $routes->get('/frames/{id}', [FrameController::class, 'show']);
    $routes->post('/frames/{id}/copy', [FrameController::class, 'copy']);
    $routes->post('/frames/{id}/domains', [FrameController::class, 'addDomain']);
    $routes->post('/frames/{id}/items', [FrameController::class, 'addItem']);
    $routes->patch('/frames/{id}/items/{item_id}', [FrameController::class, 'updateItem']);
    $routes->post('/frames/{id}/activate', [FrameController::class, 'activate']);

    $routes->post('/observations', [ObservationController::class, 'store']);
    $routes->get('/observations', [ObservationController::class, 'index']);
    $routes->get('/observations/coverage', [ObservationController::class, 'coverage']);

    $routes->get('/progress-books', [ProgressBookController::class, 'index']);
    $routes->get('/progress-books/{id}', [ProgressBookController::class, 'show']);
    $routes->post('/progress-books', [ProgressBookController::class, 'store']);
    $routes->put('/progress-books/{id}', [ProgressBookController::class, 'write']);
    $routes->post('/progress-books/{id}/submit', [ProgressBookController::class, 'submit']);
    $routes->post('/progress-books/{id}/validate', [ProgressBookController::class, 'validate']);
    $routes->post('/progress-books/{id}/return', [ProgressBookController::class, 'sendBack']);
    $routes->post('/progress-books/{id}/publish', [ProgressBookController::class, 'publish']);

    $routes->get('/lifebook/entries', [LifeBookController::class, 'index']);
    $routes->post('/lifebook/entries', [LifeBookController::class, 'store']);
    $routes->patch('/lifebook/entries/{id}', [LifeBookController::class, 'update']);
    $routes->post('/lifebook/entries/{id}/media', [LifeBookController::class, 'addMedia']);
    $routes->post('/lifebook/entries/{id}/publish', [LifeBookController::class, 'publish']);
    $routes->post('/lifebook/entries/{id}/withdraw', [LifeBookController::class, 'withdraw']);
    $routes->post('/lifebook/media/{id}/tag', [LifeBookController::class, 'tag']);
    $routes->get('/lifebook/media/{id}', [LifeBookController::class, 'media']);

    $routes->get('/needs', [NeedController::class, 'index']);
    $routes->post('/needs', [NeedController::class, 'store']);
    $routes->post('/needs/{id}/orient', [NeedController::class, 'orient']);
    $routes->post('/needs/{id}/close', [NeedController::class, 'close']);

    $routes->get('/children/{id}/photo-consent', [PhotoConsentController::class, 'show']);
    $routes->post('/children/{id}/photo-consent', [PhotoConsentController::class, 'store']);
    $routes->post('/children/{id}/photo-consent/revoke', [PhotoConsentController::class, 'revoke']);
    $routes->get('/portal/photo-consent', [PhotoConsentController::class, 'portalShow']);
    $routes->post('/portal/photo-consent', [PhotoConsentController::class, 'portalGrant']);
    $routes->post('/portal/photo-consent/revoke', [PhotoConsentController::class, 'portalRevoke']);
};
