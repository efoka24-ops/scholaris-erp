<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\PublicPrimary\Referential\Http\ReferentialController;
use Scholaris\Modules\Apps\PublicSchools\Support\PublicRoutes;

return static function (PublicRoutes $routes): void {
    // EVA-01, EVA-02 : référentiel national versionné, unités d'apprentissage, paramètres effectifs (T660, T661).
    $routes->get('/frames', [ReferentialController::class, 'frames']);
    $routes->post('/frames', [ReferentialController::class, 'createFrame']);
    $routes->post('/frames/import-example', [ReferentialController::class, 'importExample']);
    $routes->get('/frames/{id}', [ReferentialController::class, 'frame']);
    $routes->get('/units', [ReferentialController::class, 'units']);
    $routes->post('/units', [ReferentialController::class, 'saveUnit']);
    $routes->post('/units/generate', [ReferentialController::class, 'generateUnits']);
    $routes->get('/settings', [ReferentialController::class, 'settings']);
};
