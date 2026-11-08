<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Secondary\Councils\Http\CouncilsController;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryRoutes;

/**
 * Routes Councils : conseil de classe et bulletins (noyau, sans module activable ; les actions du conseil restent sur les routes du
 * socle /class-councils), puis le sous-module Orientation gardé par le module `orientation`.
 */
return static function (SecondaryRoutes $routes): void {
    $routes->get('/councils/rule-draft', [CouncilsController::class, 'ruleDraft']);
    $routes->get('/councils/report-card-settings', [CouncilsController::class, 'settings']);
    $routes->post('/councils/report-card-settings/preset', [CouncilsController::class, 'presetSettings']);
    $routes->get('/councils/{councilId}/report-cards/{personId}', [CouncilsController::class, 'reportCard']);
    (require __DIR__ . '/Orientation/routes.php')($routes);
};
