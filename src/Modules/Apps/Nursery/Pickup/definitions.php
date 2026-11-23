<?php

declare(strict_types=1);

use function DI\autowire;

use Scholaris\Modules\Apps\Nursery\Pickup\Application\AlertSender;
use Scholaris\Modules\Apps\Nursery\Pickup\Application\AuthorizedPersonsService;
use Scholaris\Modules\Apps\Nursery\Pickup\Application\ConsistencyCheck;
use Scholaris\Modules\Apps\Nursery\Pickup\Application\GateJournal;
use Scholaris\Modules\Apps\Nursery\Pickup\Application\HandoverService;
use Scholaris\Modules\Apps\Nursery\Pickup\Application\PackageService;
use Scholaris\Modules\Apps\Nursery\Pickup\Application\PickupBatchHandler;
use Scholaris\Modules\Apps\Nursery\Pickup\Application\PickupCodeService;
use Scholaris\Modules\Apps\Nursery\Pickup\Application\PickupWatch;
use Scholaris\Modules\Apps\Nursery\Pickup\Application\RegisterService;
use Scholaris\Modules\Apps\Nursery\Pickup\Application\RestrictionService;
use Scholaris\Modules\Apps\Nursery\Pickup\Console\ConsistencyCheckCommand;
use Scholaris\Modules\Apps\Nursery\Pickup\Console\PickupWatchCommand;
use Scholaris\Modules\Apps\Nursery\Pickup\Contracts\ArrivalJournal;
use Scholaris\Modules\Apps\Nursery\Pickup\Http\AuthorizedPersonsController;
use Scholaris\Modules\Apps\Nursery\Pickup\Http\HandoverController;
use Scholaris\Modules\Apps\Nursery\Pickup\Http\RegisterController;
use Scholaris\Modules\Apps\Nursery\Pickup\Http\RestrictionController;

return [
    GateJournal::class => autowire(),
    ArrivalJournal::class => \DI\get(GateJournal::class),
    AlertSender::class => autowire(),
    AuthorizedPersonsService::class => autowire(),
    RestrictionService::class => autowire(),
    PickupCodeService::class => autowire(),
    HandoverService::class => autowire(),
    PickupWatch::class => autowire(),
    RegisterService::class => autowire(),
    PackageService::class => autowire(),
    ConsistencyCheck::class => autowire(),
    PickupBatchHandler::class => autowire(),
    'sync.handlers.' . PickupBatchHandler::KIND => \DI\get(PickupBatchHandler::class),
    'console.command.' . PickupWatchCommand::NAME => autowire(PickupWatchCommand::class),
    'console.command.' . ConsistencyCheckCommand::NAME => autowire(ConsistencyCheckCommand::class),
    AuthorizedPersonsController::class => autowire(),
    RestrictionController::class => autowire(),
    HandoverController::class => autowire(),
    RegisterController::class => autowire(),
];
