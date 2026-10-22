<?php

declare(strict_types=1);

use function DI\autowire;

use Scholaris\Modules\Apps\Nursery\Attendance\Application\CallService;
use Scholaris\Modules\Apps\Nursery\Attendance\Http\CallController;

return [
    CallService::class => autowire(),
    CallController::class => autowire(),
];
