<?php

declare(strict_types=1);

use function DI\autowire;

use Scholaris\Modules\Apps\PublicSchools\Territory\Http\TerritorySchoolsController;

return [TerritorySchoolsController::class => autowire()];
