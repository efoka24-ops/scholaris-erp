<?php

declare(strict_types=1);

use function DI\autowire;

use Scholaris\Modules\Apps\PublicSchools\Contracts\SchoolRegistry;
use Scholaris\Modules\Apps\PublicSchools\Schools\Application\InfrastructureCensusSection;
use Scholaris\Modules\Apps\PublicSchools\Schools\Application\InfrastructureSnapshotService;
use Scholaris\Modules\Apps\PublicSchools\Schools\Application\InfrastructureYearService;
use Scholaris\Modules\Apps\PublicSchools\Schools\Application\SchoolFicheService;
use Scholaris\Modules\Apps\PublicSchools\Schools\Application\SchoolPhotoService;
use Scholaris\Modules\Apps\PublicSchools\Schools\Http\InfrastructureController;
use Scholaris\Modules\Apps\PublicSchools\Schools\Http\PhotoController;
use Scholaris\Modules\Apps\PublicSchools\Schools\Http\RegistryController;
use Scholaris\Modules\Apps\PublicSchools\Schools\Http\SchoolAccess;
use Scholaris\Modules\Apps\PublicSchools\Schools\Http\SchoolController;
use Scholaris\Modules\Apps\PublicSchools\Schools\Infrastructure\PdoSchoolRegistry;
use Scholaris\Modules\Apps\PublicSchools\Schools\Policies\RegistryPolicy;

return [
    SchoolRegistry::class => autowire(PdoSchoolRegistry::class),
    SchoolAccess::class => autowire(),
    SchoolFicheService::class => autowire(),
    InfrastructureYearService::class => autowire(),
    SchoolPhotoService::class => autowire(),
    SchoolController::class => autowire(),
    InfrastructureController::class => autowire(),
    PhotoController::class => autowire(),
    InfrastructureSnapshotService::class => autowire(),
    RegistryController::class => autowire(),
    RegistryPolicy::class => autowire(),
    InfrastructureCensusSection::class => autowire(),
    'public_schools.census_sections.schools' => [InfrastructureCensusSection::class],
    'reporting.territory_aggregates.public_schools.schools' => [InfrastructureCensusSection::class],
];
