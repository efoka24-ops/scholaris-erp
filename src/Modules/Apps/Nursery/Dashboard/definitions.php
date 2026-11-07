<?php

declare(strict_types=1);

use function DI\autowire;

use Scholaris\Modules\Apps\Nursery\Dashboard\Application\DashboardService;
use Scholaris\Modules\Apps\Nursery\Dashboard\Http\DashboardController;

return [
    DashboardService::class => autowire(),
    DashboardController::class => autowire(),
];
