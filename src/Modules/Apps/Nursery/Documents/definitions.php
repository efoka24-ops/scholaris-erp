<?php

declare(strict_types=1);

use function DI\autowire;

use Scholaris\Modules\Apps\Nursery\Documents\Application\DocumentRequests;
use Scholaris\Modules\Apps\Nursery\Documents\Application\NurseryDocuments;
use Scholaris\Modules\Apps\Nursery\Documents\Http\DocumentController;

return [
    NurseryDocuments::class => autowire(),
    DocumentRequests::class => autowire(),
    DocumentController::class => autowire(),
];
