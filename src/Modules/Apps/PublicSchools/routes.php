<?php

declare(strict_types=1);

use Scholaris\Core\Http\RouteRegistry;
use Scholaris\Modules\Apps\PublicSchools\Support\PublicRoutes;

/**
 * Unique routes.php de l'application chargé par le noyau. Il inclut celui de chaque sous-module (`PublicSchools/<Module>/routes.php`,
 * fonction recevant un PublicRoutes) : aucun sous-module ne modifie ce fichier. Toute route passe par PublicRoutes
 * (préfixe /public, WriteGate avant chaque écriture). Squelette vide : les routes arrivent avec les sous-modules.
 */
return static function (RouteRegistry $registry): void {
    $routes = new PublicRoutes($registry);

    foreach (glob(__DIR__ . '/*/routes.php') ?: [] as $file) {
        (require $file)($routes);
    }
};
