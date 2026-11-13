<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Primary\Support\PrimaryRoutes;
use Scholaris\Modules\Apps\Primary\Tutelage\Http\TutelageController;

/** Exports de tutelle (lectures seules, permises même application suspendue). */
return static function (PrimaryRoutes $routes): void {
    $routes->get('/tutelage/types', [TutelageController::class, 'types']);
    $routes->get('/tutelage/exports/{type}', [TutelageController::class, 'export']);
};
