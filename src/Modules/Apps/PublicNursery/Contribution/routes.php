<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\PublicNursery\Contribution\Http\ContributionController;
use Scholaris\Modules\Apps\PublicNursery\Support\NurseryRoutes;

return static function (NurseryRoutes $routes): void {
    $routes->get('/contributions', [ContributionController::class, 'index']);
    $routes->get('/contributions/summary', [ContributionController::class, 'summary']);
    $routes->get('/contributions/unpaid', [ContributionController::class, 'unpaid']);
    $routes->post('/contributions', [ContributionController::class, 'store']);
    $routes->post('/contributions/{id}/cancel', [ContributionController::class, 'cancel']);
    $routes->get('/sections/capacity', [ContributionController::class, 'capacity']);
};
