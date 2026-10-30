<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Secondary\Setup\Http\ProfileController;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryRoutes;

/** Paramétrage (noyau, préfixe /secondary). Les routes d'ouverture et de changement de profil viennent avec T815 et T838. */
return static function (SecondaryRoutes $routes): void {
    $routes->get('/setup/profile', [ProfileController::class, 'show']);
};
