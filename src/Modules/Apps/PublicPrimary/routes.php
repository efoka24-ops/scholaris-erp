<?php

declare(strict_types=1);

use Scholaris\Core\Http\RouteRegistry;

/** Routeur racine PublicPrimary requis par le chargeur de modules ; les sous-modules restent propriétaires de leurs routes. */
return static function (RouteRegistry $registry): void {
    foreach (glob(__DIR__ . '/*/routes.php') ?: [] as $file) {
        (require $file)($registry);
    }
};