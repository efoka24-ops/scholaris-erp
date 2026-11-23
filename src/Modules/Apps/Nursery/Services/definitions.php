<?php

declare(strict_types=1);

use function DI\autowire;

use Scholaris\Modules\Apps\Nursery\Services\Application\ServiceAttendance;
use Scholaris\Modules\Apps\Nursery\Services\Application\ServiceBilling;
use Scholaris\Modules\Apps\Nursery\Services\Application\ServiceCatalog;
use Scholaris\Modules\Apps\Nursery\Services\Http\ServiceController;

return [
    ServiceCatalog::class => autowire(),
    ServiceBilling::class => autowire(),
    ServiceAttendance::class => autowire(),
    ServiceController::class => autowire(),
];
