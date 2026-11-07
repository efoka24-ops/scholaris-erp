<?php

declare(strict_types=1);

use function DI\autowire;

use Scholaris\Modules\Apps\PublicSchools\Staff\Application\StaffClock;
use Scholaris\Modules\Apps\PublicSchools\Staff\Application\StaffService;
use Scholaris\Modules\Apps\PublicSchools\Staff\Http\StaffController;
use Scholaris\Modules\Apps\PublicSchools\Staff\Infrastructure\PdoStaffRecords;

return [
    StaffClock::class => autowire(),
    PdoStaffRecords::class => autowire(),
    StaffService::class => autowire(),
    StaffController::class => autowire(),
];
