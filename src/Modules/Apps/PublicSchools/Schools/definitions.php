<?php

declare(strict_types=1);

use function DI\autowire;

use Scholaris\Modules\Apps\PublicSchools\Contracts\SchoolRegistry;
use Scholaris\Modules\Apps\PublicSchools\Schools\Application\InfrastructureSnapshotService;
use Scholaris\Modules\Apps\PublicSchools\Schools\Http\RegistryController;
use Scholaris\Modules\Apps\PublicSchools\Schools\Http\SchoolController;
use Scholaris\Modules\Apps\PublicSchools\Schools\Infrastructure\PdoSchoolRegistry;
use Scholaris\Modules\Apps\PublicSchools\Schools\Policies\RegistryPolicy;

return [
    SchoolRegistry::class => autowire(PdoSchoolRegistry::class),
    SchoolController::class => autowire(),
    InfrastructureSnapshotService::class => autowire(),
    RegistryController::class => autowire(),
    RegistryPolicy::class => autowire(),
];
