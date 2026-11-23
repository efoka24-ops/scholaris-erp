<?php

declare(strict_types=1);

use function DI\autowire;

use Scholaris\Modules\Apps\Nursery\Messages\Application\SectionMessageService;
use Scholaris\Modules\Apps\Nursery\Messages\Http\MessageController;

return [
    SectionMessageService::class => autowire(),
    MessageController::class => autowire(),
];
