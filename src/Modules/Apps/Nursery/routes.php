<?php

declare(strict_types=1);

use Scholaris\Core\Http\RouteRegistry;
use Scholaris\Modules\Apps\Nursery\Support\NurseryRoutes;

/** Routeur racine : chaque sous-module (`<Module>/routes.php`, fonction recevant un NurseryRoutes) y est inclus. */
return static function (RouteRegistry $registry): void {
    $routes = new NurseryRoutes($registry);
    foreach (glob(__DIR__ . '/*/routes.php') ?: [] as $file) {
        (require $file)($routes);
    }
};
