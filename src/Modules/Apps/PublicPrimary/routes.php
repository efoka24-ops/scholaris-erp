<?php

declare(strict_types=1);

use Scholaris\Core\Http\RouteRegistry;
use Scholaris\Modules\Apps\PublicSchools\Support\PublicRoutes;

/**
 * Routeur racine de la Primaire publique : inclut `PublicPrimary/<Module>/routes.php` (fonction recevant un PublicRoutes, préfixe
 * `/public-primary`, WriteGate avant chaque écriture). Exception transitoire : le sous-module Registry (ancien registre, supprimé
 * par T625) enregistre encore ses routes directement sur le RouteRegistry ; il disparaît avec T625 et cette exception avec lui.
 */
return static function (RouteRegistry $registry): void {
    $routes = new PublicRoutes($registry, PublicRoutes::PRIMARY_PREFIX);

    foreach (glob(__DIR__ . '/*/routes.php') ?: [] as $file) {
        (require $file)(basename(dirname($file)) === 'Registry' ? $registry : $routes);
    }
};
