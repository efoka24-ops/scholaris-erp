<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\PublicNursery\Portal\Http\AllergyController;
use Scholaris\Modules\Apps\PublicNursery\Support\NurseryRoutes;

return static function (NurseryRoutes $routes): void {
    $routes->post('/portal/allergies', [AllergyController::class, 'declare']);
    $routes->get('/portal/allergies/section', [AllergyController::class, 'section']);
};
