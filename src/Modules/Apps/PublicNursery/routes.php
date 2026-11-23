<?php

declare(strict_types=1);

use Scholaris\Core\Http\RouteRegistry;
use Scholaris\Modules\Apps\PublicNursery\Support\NurseryRoutes;

/**
 * Routeur racine PublicNursery : chaque sous-module (`<Module>/routes.php`, fonction recevant un NurseryRoutes) y est inclus.
 * Toute route passe par NurseryRoutes (préfixe /public-nursery, NurseryWriteGate avant chaque écriture).
 */
return static function (RouteRegistry $registry): void {
    $routes = new NurseryRoutes($registry);

    foreach (glob(__DIR__ . '/*/routes.php') ?: [] as $file) {
        (require $file)($routes);
    }
};
