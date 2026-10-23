<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Nursery\Sections\Http\SectionController;
use Scholaris\Modules\Apps\Nursery\Support\NurseryRoutes;

return static function (NurseryRoutes $routes): void {
    $routes->get('/sections', [SectionController::class, 'index']);
    $routes->post('/sections', [SectionController::class, 'store']);
    $routes->patch('/sections/{id}', [SectionController::class, 'update']);
    $routes->get('/sections/{id}/children', [SectionController::class, 'children']);
};
