<?php

declare(strict_types=1);

use function DI\autowire;

use Scholaris\Modules\Apps\PublicSchools\Contracts\ClassRoster;
use Scholaris\Modules\Apps\PublicSchools\Pupils\Application\ClassProfileService;
use Scholaris\Modules\Apps\PublicSchools\Pupils\Http\ClassController;
use Scholaris\Modules\Apps\PublicSchools\Pupils\Infrastructure\PdoClassRoster;

return [
    ClassRoster::class => autowire(PdoClassRoster::class),
    ClassProfileService::class => autowire(),
    PdoClassRoster::class => autowire(),
    ClassController::class => autowire(),
];
