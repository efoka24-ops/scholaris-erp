<?php

declare(strict_types=1);

use function DI\autowire;

use Scholaris\Modules\Apps\Nursery\Transition\Application\ClosureReadiness;
use Scholaris\Modules\Apps\Nursery\Transition\Application\LiaisonService;
use Scholaris\Modules\Apps\Nursery\Transition\Application\NurseryPromotionPlanner;
use Scholaris\Modules\Apps\Nursery\Transition\Application\TransitionService;
use Scholaris\Modules\Apps\Nursery\Transition\Http\TransitionController;

return [
    TransitionService::class => autowire(),
    LiaisonService::class => autowire(),
    ClosureReadiness::class => autowire(),
    NurseryPromotionPlanner::class => autowire(),
    TransitionController::class => autowire(),
    'organisation.closure.checks.nursery' => [ClosureReadiness::class],
    NurseryPromotionPlanner::CONTAINER_KEY => [NurseryPromotionPlanner::class],
];
