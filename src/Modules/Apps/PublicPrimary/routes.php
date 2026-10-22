<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\PublicSchools\Support\PublicRoutes;

/**
 * Routeur racine de la Primaire publique : inclut les sous-modules derrière PublicRoutes et WriteGate.
 */
return static function (\Scholaris\Core\Http\RouteRegistry $registry): void {
    $routes = new PublicRoutes($registry, PublicRoutes::PRIMARY_PREFIX);

    foreach (glob(__DIR__ . '/*/routes.php') ?: [] as $file) {
        // « Registry » est l'ancien registre, repris par PublicSchools/Schools : s'il subsiste sur un serveur (déploiement sans
        // suppression), son routes.php n'est plus chargé.
        if (basename(dirname($file)) === 'Registry') {
            continue;
        }
        (require $file)($routes);
    }
};
