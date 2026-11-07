<?php

declare(strict_types=1);

use function DI\autowire;

use Scholaris\Modules\Apps\Nursery\Core\Http\SettingsController;
use Scholaris\Modules\Apps\Nursery\Policies\ScopeResolver;
use Scholaris\Modules\Apps\Nursery\Support\Access;
use Scholaris\Modules\Apps\Nursery\Support\GatedAction;
use Scholaris\Modules\Apps\Nursery\Support\NurseryClock;
use Scholaris\Modules\Apps\Nursery\Support\NurserySettings;
use Scholaris\Modules\Apps\Nursery\Support\Store;
use Scholaris\Modules\Apps\Nursery\Support\WriteGate;

return [
    WriteGate::class => autowire(),
    GatedAction::class => autowire(),
    ScopeResolver::class => autowire(),
    Access::class => autowire(),
    Store::class => autowire(),
    NurserySettings::class => autowire(),
    NurseryClock::class => static fn (): NurseryClock => new NurseryClock(),
    SettingsController::class => autowire(),
];
