<?php

declare(strict_types=1);

use function DI\autowire;

use Scholaris\Modules\Apps\Nursery\Development\Application\FrameService;
use Scholaris\Modules\Apps\Nursery\Development\Application\LifeBookService;
use Scholaris\Modules\Apps\Nursery\Development\Application\MediaRetention;
use Scholaris\Modules\Apps\Nursery\Development\Application\NeedService;
use Scholaris\Modules\Apps\Nursery\Development\Application\ObservationBatchHandler;
use Scholaris\Modules\Apps\Nursery\Development\Application\ObservationService;
use Scholaris\Modules\Apps\Nursery\Development\Application\PhotoConsentService;
use Scholaris\Modules\Apps\Nursery\Development\Application\ProgressBookService;
use Scholaris\Modules\Apps\Nursery\Development\Application\TermsGuard;
use Scholaris\Modules\Apps\Nursery\Development\Console\ObservationRemindersCommand;
use Scholaris\Modules\Apps\Nursery\Development\Console\PurgeMediaCommand;
use Scholaris\Modules\Apps\Nursery\Development\Http\FrameController;
use Scholaris\Modules\Apps\Nursery\Development\Http\LifeBookController;
use Scholaris\Modules\Apps\Nursery\Development\Http\NeedController;
use Scholaris\Modules\Apps\Nursery\Development\Http\ObservationController;
use Scholaris\Modules\Apps\Nursery\Development\Http\PhotoConsentController;
use Scholaris\Modules\Apps\Nursery\Development\Http\ProgressBookController;

return [
    TermsGuard::class => autowire(),
    FrameService::class => autowire(),
    ObservationService::class => autowire(),
    ProgressBookService::class => autowire(),
    LifeBookService::class => autowire(),
    NeedService::class => autowire(),
    ObservationBatchHandler::class => autowire(),
    'sync.handlers.' . ObservationBatchHandler::KIND => \DI\get(ObservationBatchHandler::class),
    'console.command.' . ObservationRemindersCommand::NAME => autowire(ObservationRemindersCommand::class),
    FrameController::class => autowire(),
    ObservationController::class => autowire(),
    ProgressBookController::class => autowire(),
    LifeBookController::class => autowire(),
    NeedController::class => autowire(),
    PhotoConsentService::class => autowire(),
    PhotoConsentController::class => autowire(),
    MediaRetention::class => autowire(),
    'console.command.' . PurgeMediaCommand::NAME => autowire(PurgeMediaCommand::class),
];
