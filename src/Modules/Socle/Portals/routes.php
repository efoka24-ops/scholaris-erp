<?php

declare(strict_types=1);

use Scholaris\Core\Http\RouteRegistry;
use Scholaris\Modules\Socle\Portals\Http\PortalController;

/** Routes du service S12 Portails : authentifiées, sans établissement actif (un seul compte pour tout le groupe). */
return static function (RouteRegistry $routes): void {
    $session = ['tenant' => false];

    $routes->add('GET', '/portal/family', [PortalController::class, 'family'], $session);
    $routes->add('GET', '/portal/learner', [PortalController::class, 'learner'], $session);
    $routes->add('GET', '/portal/teacher', [\Scholaris\Modules\Socle\Portals\Http\TeacherPortalController::class, 'teacher'], $session);
};
