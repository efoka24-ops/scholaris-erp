<?php

declare(strict_types=1);

use function DI\autowire;

use Scholaris\Modules\Apps\Nursery\Sections\Application\ChildDirectory;
use Scholaris\Modules\Apps\Nursery\Sections\Application\SectionService;
use Scholaris\Modules\Apps\Nursery\Sections\Http\SectionController;

return [
    SectionService::class => autowire(),
    ChildDirectory::class => autowire(),
    SectionController::class => autowire(),
];
