<?php

declare(strict_types=1);

use Scholaris\Core\Http\RouteRegistry;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryRoutes;

/** Routes racine : chaque sous-module reçoit un SecondaryRoutes (droit d'usage, garde de module et garde de plateforme automatiques). */
return static function (RouteRegistry $registry): void {
    $routes = new SecondaryRoutes($registry);
    foreach (glob(__DIR__ . '/*/routes.php') ?: [] as $file) {
        (require $file)($routes);
    }
};
