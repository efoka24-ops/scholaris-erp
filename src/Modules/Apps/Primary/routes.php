<?php

declare(strict_types=1);

use Scholaris\Core\Http\RouteRegistry;
use Scholaris\Modules\Apps\Primary\Support\PrimaryRoutes;

return static function (RouteRegistry $registry): void {
    $routes = new PrimaryRoutes($registry);
    foreach (glob(__DIR__ . '/*/routes.php') ?: [] as $file) {
        (require $file)($routes);
    }
};
