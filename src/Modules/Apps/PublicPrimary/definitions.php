<?php

declare(strict_types=1);

use function DI\autowire;

use Scholaris\Modules\Apps\PublicPrimary\Registry\Application\InfrastructureSnapshotService;
use Scholaris\Modules\Apps\PublicPrimary\Registry\Policies\RegistryPolicy;

return [
    InfrastructureSnapshotService::class => autowire(),
    RegistryPolicy::class => autowire(),
];
