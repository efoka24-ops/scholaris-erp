<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Nursery\Core\Http\SettingsController;
use Scholaris\Modules\Apps\Nursery\Support\NurseryRoutes;

return static function (NurseryRoutes $routes): void {
    $routes->get('/settings', [SettingsController::class, 'index']);
};
