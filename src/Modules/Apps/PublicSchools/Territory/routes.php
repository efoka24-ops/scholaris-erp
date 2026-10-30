<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\PublicSchools\Support\PublicRoutes;
use Scholaris\Modules\Apps\PublicSchools\Territory\Http\DashboardController;
use Scholaris\Modules\Apps\PublicSchools\Territory\Http\TerritorySchoolsController;

return static function (PublicRoutes $routes): void {
    $routes->get('/schools/receiving', [TerritorySchoolsController::class, 'receiving']);
    $routes->get('/territories/{id}/schools', [TerritorySchoolsController::class, 'index']);
    $routes->get('/territories/{id}/schools/{school}/pupils', [TerritorySchoolsController::class, 'pupils']);
    // T690 : tableau de bord territorial (API-12, DOC-28). La route statique « entry » précède la route variable.
    $routes->get('/dashboards/entry', [DashboardController::class, 'entry']);
    $routes->get('/dashboards/{territory}', [DashboardController::class, 'show'], ['tenant' => false]);
    $routes->get('/dashboards/{territory}/children', [DashboardController::class, 'children'], ['tenant' => false]);
    $routes->post('/dashboards/{territory}/document', [DashboardController::class, 'document']);
};
