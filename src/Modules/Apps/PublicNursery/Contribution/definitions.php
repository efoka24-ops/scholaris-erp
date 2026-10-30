<?php

declare(strict_types=1);

use function DI\autowire;
use function DI\create;

use Scholaris\Modules\Apps\PublicNursery\Capacity\Application\CapacityService;
use Scholaris\Modules\Apps\PublicNursery\Contribution\Application\ContributionService;
use Scholaris\Modules\Apps\PublicNursery\Contribution\Http\ContributionController;
use Scholaris\Modules\Apps\PublicNursery\Support\PublicNurseryClock;
use Scholaris\Modules\Apps\PublicNursery\Support\RequestContext;

return [
    ContributionService::class => autowire(),
    ContributionController::class => autowire(),
    CapacityService::class => autowire(),
    RequestContext::class => autowire(),
    PublicNurseryClock::class => create(PublicNurseryClock::class),
];
