<?php

declare(strict_types=1);

use function DI\autowire;

use Scholaris\Modules\Apps\PublicSchools\Attendance\Application\AttendanceBatchHandler;
use Scholaris\Modules\Apps\PublicSchools\Attendance\Application\DailyAttendanceService;
use Scholaris\Modules\Apps\PublicSchools\Attendance\Http\AttendanceController;

return [
    DailyAttendanceService::class => autowire(),
    AttendanceController::class => autowire(),
    AttendanceBatchHandler::class => autowire(),
    'sync.handlers.public.attendance' => autowire(AttendanceBatchHandler::class),
];
