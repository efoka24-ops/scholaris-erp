<?php

declare(strict_types=1);

use function DI\autowire;

use Scholaris\Modules\Apps\PublicSchools\Territory\Http\TerritorySchoolsController;
use Scholaris\Modules\Apps\PublicSchools\Territory\Infrastructure\PdoOfficialSchools;

return [
    PdoOfficialSchools::class => autowire(),
    TerritorySchoolsController::class => autowire(),
];
