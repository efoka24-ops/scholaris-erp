<?php

declare(strict_types=1);

use function DI\autowire;

use Scholaris\Modules\Apps\PublicNursery\Gate\Application\GateService;
use Scholaris\Modules\Apps\PublicNursery\Gate\Console\PickupAuditCommand;
use Scholaris\Modules\Apps\PublicNursery\Gate\Console\PickupWatchCommand;
use Scholaris\Modules\Apps\PublicNursery\Gate\Http\GateController;

return [
    GateService::class => autowire(),
    GateController::class => autowire(),
    'console.command.' . PickupWatchCommand::NAME => autowire(PickupWatchCommand::class),
    'console.command.' . PickupAuditCommand::NAME => autowire(PickupAuditCommand::class),
];
