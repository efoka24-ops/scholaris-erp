<?php

declare(strict_types=1);

use function DI\autowire;

use Scholaris\Modules\Apps\Nursery\Enrolment\Application\EnrolmentService;
use Scholaris\Modules\Apps\Nursery\Enrolment\Http\ChildController;
use Scholaris\Modules\Apps\Nursery\Enrolment\Http\EnrolmentController;

return [
    EnrolmentService::class => autowire(),
    ChildController::class => autowire(),
    EnrolmentController::class => autowire(),
];
