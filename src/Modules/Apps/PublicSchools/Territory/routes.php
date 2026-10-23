<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\PublicSchools\Support\PublicRoutes;
use Scholaris\Modules\Apps\PublicSchools\Territory\Http\TerritorySchoolsController;

return static function (PublicRoutes $routes): void {
    $routes->get('/schools/receiving', [TerritorySchoolsController::class, 'receiving']);
    $routes->get('/territories/{id}/schools', [TerritorySchoolsController::class, 'index']);
    $routes->get('/territories/{id}/schools/{school}/pupils', [TerritorySchoolsController::class, 'pupils']);
};
