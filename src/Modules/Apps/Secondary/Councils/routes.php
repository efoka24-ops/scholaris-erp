<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Secondary\Support\SecondaryRoutes;

/** Enregistreur autonome des routes Orientation (le sous-module racine charge uniquement les fichiers routes.php). */
return static function (SecondaryRoutes $routes): void {
    (require __DIR__ . '/Orientation/routes.php')($routes);
};
