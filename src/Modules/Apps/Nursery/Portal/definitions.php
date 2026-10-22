<?php

declare(strict_types=1);

use function DI\autowire;

use Scholaris\Modules\Apps\Nursery\Portal\Http\PortalController;

return [
    PortalController::class => autowire(),
];
