<?php

declare(strict_types=1);

use Scholaris\Core\Http\RouteRegistry;
use Scholaris\Modules\Apps\Higher\Http\Support\MeController;
use Scholaris\Modules\Apps\Higher\Support\HigherRoutes;

/**
 * Unique routes.php de l'application Supérieur chargé par le noyau. Il inclut celui de chaque sous-module
 * (`Higher/<Module>/routes.php`, fonction recevant un HigherRoutes) : aucun sous-module ne modifie ce fichier.
 * Toute route passe par HigherRoutes (préfixe /higher, WriteGate avant chaque écriture).
 */
return static function (RouteRegistry $registry): void {
    $routes = new HigherRoutes($registry);
    $routes->get('/me', [MeController::class, 'show']);

    foreach (glob(__DIR__ . '/*/routes.php') ?: [] as $file) {
        (require $file)($routes);
    }
};
